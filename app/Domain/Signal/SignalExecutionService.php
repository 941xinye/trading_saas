<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\ExchangeManager;
use App\Domain\Signal\Exceptions\SignalExecutionException;
use App\Domain\Strategy\OrderExecutor;
use App\Models\Signal;
use App\Models\StrategyInstance;
use App\Support\AesGcmCrypt;
use App\Support\KeyResolver;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 信号执行（信号 → 交易联动的第一步：人工确认的一键下单）。
 *
 * 安全边界（全部前置校验，任一不满足即拒绝）：
 *  - 仅 long/short 信号；同租户、同交易对、实例处于 running
 *  - 信号在 TTL 窗口内（默认 15 分钟），避免过期追单
 *  - 名义额 > 0、不超过配置上限与实例分配资金；现货账户拒绝做空
 *  - 凭据解密失败 / 账户缺失直接拒绝
 *
 * 幂等：client_order_id = sig_{signalId}_{instanceId}，同一信号在同一实例上
 * 只能成功执行一次（OrderExecutor 的 unique(tenant, cid) 兜底并发与重试）。
 * 下单本身复用 OrderExecutor：权益/日配额闸门 + LOT_SIZE 对齐 + 先落库后下单。
 */
final class SignalExecutionService
{
    public function __construct(
        private readonly ExchangeManager $exchangeManager,
        private readonly KeyResolver $keyResolver,
        private readonly AesGcmCrypt $crypt,
        private readonly OrderExecutor $executor,
    ) {}

    /**
     * 按信号方向市价下单。
     *
     * @return array{
     *     signal:Signal,
     *     instance:StrategyInstance,
     *     order:ExchangeOrder,
     *     side:string,
     *     notional:string,
     *     reference_price:string,
     *     client_order_id:string
     * }
     *
     * @throws SignalExecutionException 校验失败 / 重复执行 / 交易所拒单
     */
    public function execute(Signal $signal, StrategyInstance $instance, string $notional): array
    {
        $tenantId = (int) $signal->tenant_id;

        if ($tenantId !== (int) $instance->tenant_id) {
            throw new SignalExecutionException('信号与策略实例不属于同一租户。');
        }

        if (! in_array((string) $signal->direction, ['long', 'short'], true)) {
            throw new SignalExecutionException('仅 long / short 方向的信号可执行下单。');
        }

        if (mb_strtoupper((string) $signal->symbol) !== mb_strtoupper((string) $instance->symbol)) {
            throw new SignalExecutionException(sprintf(
                '交易对不匹配：信号为 %s，实例为 %s。',
                $signal->symbol,
                $instance->symbol,
            ));
        }

        if ((string) $instance->status !== 'running') {
            throw new SignalExecutionException('实例未运行，请先启动再执行信号。', conflict: true);
        }

        $ttl = (int) config('signal.execution.ttl_minutes', 15);
        if ($signal->triggered_at === null
            || $signal->triggered_at->copy()->addMinutes($ttl)->isPast()) {
            throw new SignalExecutionException("信号已超过 {$ttl} 分钟执行窗口。");
        }

        if (! is_numeric($notional) || bccomp($notional, '0', 8) <= 0) {
            throw new SignalExecutionException('执行名义额必须大于 0。');
        }

        $maxNotional = (string) config('signal.execution.max_notional', '10000');
        if (bccomp($notional, $maxNotional, 8) === 1) {
            throw new SignalExecutionException("执行名义额不能超过 {$maxNotional} USDT。");
        }

        $allocated = (string) $instance->allocated_capital;
        if (bccomp($allocated, '0', 8) === 1 && bccomp($notional, $allocated, 8) === 1) {
            throw new SignalExecutionException('执行名义额不能超过实例分配资金。');
        }

        $account = DB::table('exchange_accounts')
            ->where('tenant_id', $tenantId)
            ->where('id', (int) $instance->exchange_account_id)
            ->first(['exchange_id', 'market', 'encrypted_api_key', 'encrypted_api_secret']);
        if ($account === null) {
            throw new SignalExecutionException('实例绑定的交易所账户不存在。');
        }

        $market = (string) $account->market;
        if ($market === 'spot' && (string) $signal->direction === 'short') {
            throw new SignalExecutionException('现货账户不支持执行做空信号。');
        }

        $adapter = $this->adapterFor($tenantId, $account);

        $clientOrderId = sprintf('sig_%d_%d', (int) $signal->id, (int) $instance->id);
        $existing = DB::table('strategy_orders')
            ->where('tenant_id', $tenantId)
            ->where('client_order_id', $clientOrderId)
            ->first(['status']);
        if ($existing !== null && in_array((string) $existing->status, ['pending', 'submitted', 'partial', 'filled'], true)) {
            throw new SignalExecutionException('该信号已在实例上执行或正在执行。', conflict: true);
        }

        $referencePrice = (string) ($signal->entry_price ?? '0');
        if (bccomp($referencePrice, '0', 12) !== 1) {
            try {
                $referencePrice = (string) $adapter->getTicker((string) $signal->symbol)->lastPrice;
            } catch (Throwable) {
                throw new SignalExecutionException('无法获取参考价，信号缺少入场价且行情不可用。');
            }
        }

        $side = (string) $signal->direction === 'long' ? 'buy' : 'sell';
        $qty = bcdiv($notional, $referencePrice, 12);

        $order = $this->executor->place($tenantId, $instance, $adapter, [
            'side' => $side,
            'qty' => $qty,
            'price' => $referencePrice,
            'client_order_id' => $clientOrderId,
            'leverage' => $instance->leverage,
            'reduce_only' => false,
        ]);

        if ($order === null) {
            throw new SignalExecutionException('该信号已执行或重复提交。', conflict: true);
        }

        if ((string) $order->status === 'failed') {
            $row = DB::table('strategy_orders')
                ->where('tenant_id', $tenantId)
                ->where('client_order_id', $clientOrderId)
                ->first(['error_message']);

            throw new SignalExecutionException(sprintf(
                '下单失败：%s',
                (string) ($row->error_message ?? '交易所或风控拒绝'),
            ));
        }

        return [
            'signal' => $signal,
            'instance' => $instance,
            'order' => $order,
            'side' => $side,
            'notional' => $notional,
            'reference_price' => $referencePrice,
            'client_order_id' => $clientOrderId,
        ];
    }

    /**
     * 解密账户凭据并解析适配器（沿用 StrategyRunner 的 KEK → DEK 链路）。
     */
    private function adapterFor(int $tenantId, object $account): ExchangeAdapter
    {
        $dek = $this->keyResolver->tenantDek($tenantId);
        $apiKey = $this->crypt->decrypt((string) $account->encrypted_api_key, $dek);
        $apiSecret = $this->crypt->decrypt((string) $account->encrypted_api_secret, $dek);

        if ($apiKey === null || $apiSecret === null) {
            throw new SignalExecutionException('交易所凭据不可用，请重新绑定账户。');
        }

        $exchangeCode = (string) DB::table('exchanges')
            ->where('id', (int) $account->exchange_id)
            ->value('code');
        if ($exchangeCode === '') {
            throw new SignalExecutionException('交易所定义不存在。');
        }

        return $this->exchangeManager->adapter(
            $exchangeCode,
            (string) $account->market,
            ['api_key' => $apiKey, 'api_secret' => $apiSecret],
        );
    }
}
