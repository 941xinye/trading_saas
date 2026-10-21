<?php

declare(strict_types=1);

namespace App\Domain\Strategy;

use App\Domain\Billing\EntitlementException;
use App\Domain\Billing\Metering\QuotaExceededException;
use App\Domain\Billing\QuotaGuard;
use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 下单执行器：clientOrderId 幂等 + 精度对齐 + 落库先行。
 *
 * 流程（架构 §8 下单时序）：
 *  1. 生成 clientOrderId（模板 config('strategy.client_order_id_template')）
 *  2. strategy_orders 先落库 status=pending（unique(tenant, client_order_id) 幂等锚点）
 *  3. qty 经 symbolFilters + alignToStep 对齐（LOT_SIZE）；市价单名义额低于
 *     min_notional 时按步长追加（最多 10 次）
 *  4. adapter->placeOrder(market) → 回写 exchange_order_id/status/成交明细
 *
 * 失败语义：交易所异常 → 行置 failed 并返回 status=failed 的 DTO
 *（策略 onFill 走释放分支），不向上抛异常打断调度循环。
 */
class OrderExecutor
{
    public function __construct(private readonly QuotaGuard $quotaGuard)
    {
    }

    /**
     * clientOrderId 模板填充。Binance 限制 ≤36 字符，仅 [A-Za-z0-9._-]。
     */
    public static function generateClientOrderId(int $tenantId, int $instanceId): string
    {
        return str_replace(
            ['{tenant_id}', '{instance_id}', '{timestamp}', '{random}'],
            [$tenantId, $instanceId, (string) time(), bin2hex(random_bytes(3))],
            (string) config('strategy.client_order_id_template', '{tenant_id}_{instance_id}_{timestamp}_{random}'),
        );
    }

    /**
     * 市价下单。
     *
     * @param object                $instance strategy_instances 行（id/tenant_id/symbol）
     * @param array{side:string,qty:string,price?:?string,client_order_id?:string} $decision
     */
    public function place(int $tenantId, object $instance, ExchangeAdapter $adapter, array $decision): ?ExchangeOrder
    {
        $symbol = (string) $instance->symbol;
        $side = strtolower((string) $decision['side']);
        $qty = (string) $decision['qty'];
        $clientOrderId = (string) ($decision['client_order_id']
            ?? self::generateClientOrderId($tenantId, (int) $instance->id));

        // ---- 权益 + 日配额闸门（在任何交易所动作之前；ref=clientOrderId 幂等） ----
        try {
            $this->quotaGuard->assertEntitled($tenantId);
            $meter = $this->quotaGuard->freezeDaily($tenantId, 'order_placed', $clientOrderId);
        } catch (EntitlementException $e) {
            Log::warning('下单被权益拦截', [
                'tenant' => $tenantId, 'instance' => $instance->id, 'reason' => $e->getMessage(),
            ]);

            return $this->failedOrder($symbol, $side, $clientOrderId, $qty, 'entitlement_denied');
        } catch (QuotaExceededException $e) {
            Log::warning('下单被日配额拦截', [
                'tenant' => $tenantId, 'instance' => $instance->id, 'used' => $e->used, 'limit' => $e->limit,
            ]);

            return $this->failedOrder($symbol, $side, $clientOrderId, $qty, 'daily_order_quota_exceeded');
        }

        // 数量对齐（LOT_SIZE 向下取整）
        $filters = $adapter->symbolFilters($symbol);
        $qty = $adapter->alignToStep($qty, $filters['step_size']);
        if (bccomp($qty, '0', 12) !== 1) {
            Log::warning('Order quantity aligned to zero', [
                'instance' => $instance->id, 'symbol' => $symbol, 'raw_qty' => $decision['qty'],
            ]);
            $this->quotaGuard->release($meter);

            return $this->failedOrder($symbol, $side, $clientOrderId, $qty, 'quantity_aligned_to_zero');
        }

        // minNotional 保护：市价单名义额不足时按步长追加
        $refPrice = (string) ($decision['price'] ?? '0');
        if (bccomp($refPrice, '0', 12) !== 1) {
            try {
                $refPrice = $adapter->getTicker($symbol)->lastPrice;
            } catch (Throwable) {
                $refPrice = '0'; // 拿不到参考价则跳过 notional 检查，交给交易所校验
            }
        }
        if (bccomp($refPrice, '0', 12) === 1) {
            $notional = bcmul($qty, $refPrice, 12);
            $guard = 0;
            while (bccomp($notional, $filters['min_notional'], 12) === -1 && $guard++ < 10) {
                $qty = bcadd($qty, $filters['step_size'], 12);
                $notional = bcmul($qty, $refPrice, 12);
            }
        }

        // 名义额快照（自动跟单单日资金上限按此汇总；成交后回写实际值）
        $notional = bccomp($refPrice, '0', 12) === 1 ? bcmul($qty, $refPrice, 8) : null;

        // 幂等落库：unique(tenant_id, client_order_id)
        $leverage = isset($decision['leverage']) ? (int) $decision['leverage'] : null;
        $reduceOnly = (bool) ($decision['reduce_only'] ?? false);

        $rowId = $this->insertPendingRow($tenantId, $instance, $symbol, $side, $clientOrderId, $qty, $notional, $leverage, $reduceOnly);
        if ($rowId === null) {
            // DB 不可用或该 cid 已有生效行：未发起交易所调用，归还本次冻结（已结算的旧 meter 不受影响）
            $this->quotaGuard->release($meter);

            return null; // 落库失败且无既有行（DB 不可用）
        }

        try {
            $order = $adapter->placeOrder($symbol, $side, 'market', $qty, null, null, [
                'clientOrderId' => $clientOrderId,
                'reduceOnly' => (bool) ($decision['reduce_only'] ?? false),
                'leverage' => isset($decision['leverage']) ? (int) $decision['leverage'] : null,
            ]);
        } catch (Throwable $e) {
            Log::error('Exchange placeOrder failed', [
                'instance' => $instance->id, 'client_order_id' => $clientOrderId, 'error' => $e->getMessage(),
            ]);
            $this->updateRow($rowId, [
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'placed_at' => now(),
            ]);
            // 交易所未受理：冲正配额
            $this->quotaGuard->release($meter);

            return $this->failedOrder($symbol, $side, $clientOrderId, $qty, $e->getMessage());
        }

        // 交易所已受理（new/partial/filled）：配额结算保留
        $this->quotaGuard->settle($meter);

        $filledNotional = null;
        if (bccomp((string) $order->filledQty, '0', 12) === 1 && $order->avgFillPrice !== null) {
            $filledNotional = bcmul((string) $order->filledQty, (string) $order->avgFillPrice, 8);
        }

        $values = [
            'exchange_order_id' => $order->exchangeOrderId,
            'status' => $order->status,
            'filled_qty' => $order->filledQty,
            'avg_fill_price' => $order->avgFillPrice,
            'fee' => $order->fee ?? 0,
            'placed_at' => $order->placedAt !== null
                ? date('Y-m-d H:i:s', $order->placedAt) : now(),
            'filled_at' => $order->filledAt !== null ? date('Y-m-d H:i:s', $order->filledAt) : null,
        ];
        if ($filledNotional !== null) {
            $values['notional'] = $filledNotional;
        }

        $this->updateRow($rowId, $values);

        return $order;
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    private function insertPendingRow(
        int $tenantId,
        object $instance,
        string $symbol,
        string $side,
        string $clientOrderId,
        string $qty,
        ?string $notional,
        ?int $leverage,
        bool $reduceOnly,
    ): ?int {
        try {
            return (int) DB::table('strategy_orders')->insertGetId([
                'tenant_id' => $tenantId,
                'strategy_instance_id' => $instance->id,
                'client_order_id' => $clientOrderId,
                'symbol' => $symbol,
                'side' => $side,
                'type' => 'market',
                'quantity' => $qty,
                'notional' => $notional,
                'leverage' => $leverage,
                'reduce_only' => $reduceOnly,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            // 唯一键冲突（崩溃重放/重复信号）：复用既有行
            $existing = DB::table('strategy_orders')
                ->where('tenant_id', $tenantId)
                ->where('client_order_id', $clientOrderId)
                ->first(['id', 'status']);

            if ($existing === null) {
                Log::error('strategy_orders insert failed', ['error' => $e->getMessage()]);

                return null;
            }

            if (in_array($existing->status, ['submitted', 'partial', 'filled'], true)) {
                return null; // 已在交易所生效，幂等跳过
            }

            return (int) $existing->id; // pending/failed：允许携同 cid 重放下单
        }
    }

    private function updateRow(int $rowId, array $values): void
    {
        try {
            DB::table('strategy_orders')->where('id', $rowId)->update($values + ['updated_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('strategy_orders update failed', ['id' => $rowId, 'error' => $e->getMessage()]);
        }
    }

    private function failedOrder(
        string $symbol,
        string $side,
        string $clientOrderId,
        string $qty,
        string $error,
    ): ExchangeOrder {
        return new ExchangeOrder(
            exchangeOrderId: '0',
            clientOrderId: $clientOrderId,
            symbol: $symbol,
            side: $side,
            type: 'market',
            quantity: $qty,
            price: null,
            stopPrice: null,
            leverage: null,
            reduceOnly: false,
            status: 'failed',
            filledQty: '0',
            avgFillPrice: null,
            fee: null,
            placedAt: time(),
            filledAt: null,
        );
    }
}
