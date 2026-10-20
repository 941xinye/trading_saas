<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Exchange\ExchangeManager;
use App\Domain\Tenancy\TenantKeyResolver;
use App\Support\AesGcmCrypt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * T047 — Binance Futures testnet 真实链路验收。
 *
 * 步骤：
 *   1. 账户连通（getBalance）
 *   2. 行情连通（getTicker）
 *   3. 持仓查询（getPosition 空仓）
 *   4. 真实开仓（placeOrder BUY MARKET reduceOnly=false）
 *   5. 查询持仓（getPosition 非空）
 *   6. 真实平仓（placeOrder SELL MARKET reduceOnly=true）
 *   7. 持仓归零
 *
 * 依赖：poc:futures-testnet-setup 已执行，instance #9 存在。
 */
class PocFuturesLiveTestnetCommand extends Command
{
    protected $signature = 'poc:futures-live-testnet
        {--tenant=1 : 租户 ID}
        {--instance=9 : 策略实例 ID}
        {--qty=0.001 : 下单数量（BTC）}
        {--symbol=BTCUSDT : 交易对}';

    protected $description = 'POC: live Binance futures testnet acceptance (T047)';

    private int $pass = 0;

    private int $fail = 0;

    /** @var string[] */
    private array $details = [];

    public function handle(ExchangeManager $manager, TenantKeyResolver $keyResolver, AesGcmCrypt $crypt): int
    {
        $tenantId = (int) $this->option('tenant');
        $instanceId = (int) $this->option('instance');
        $qty = (string) $this->option('qty');
        $symbol = (string) $this->option('symbol');

        $this->info("==== T047: Binance Futures Testnet 真实链路验收 ====");
        $this->line("tenant={$tenantId} instance={$instanceId} symbol={$symbol} qty={$qty}");
        $this->newLine();

        // ---- 加载实例 + 解密凭据 ----
        $instance = DB::table('strategy_instances')->where('id', $instanceId)->first();
        if ($instance === null) {
            $this->error("Instance {$instanceId} not found. Run poc:futures-testnet-setup first.");

            return self::FAILURE;
        }

        $account = DB::table('exchange_accounts')->where('id', $instance->exchange_account_id)->first();
        $exchange = DB::table('exchanges')->where('id', $account->exchange_id)->first();

        $dek = $keyResolver->tenantDek($tenantId);
        $apiKey = $crypt->decrypt((string) $account->encrypted_api_key, $dek);
        $apiSecret = $crypt->decrypt((string) $account->encrypted_api_secret, $dek);

        if ($apiKey === null || $apiSecret === null) {
            $this->error('Exchange key/secret undecryptable. Check EXCHANGE_TESTNET_KEY/SECRET and re-run setup.');

            return self::FAILURE;
        }

        /** @var \App\Domain\Exchange\Adapters\BinanceFuturesAdapter $adapter */
        $adapter = $manager->adapter((string) $exchange->code, (string) $account->market, [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ]);

        // ---- 1. getBalance ----
        $this->info('---- 1. 账户连通（getBalance）----');
        try {
            $balance = $adapter->getBalance();
            $usdt = $balance->assets['USDT']['balance'] ?? '0';
            $this->check(true, "getBalance OK: USDT balance={$usdt}");
        } catch (\Throwable $e) {
            $this->check(false, 'getBalance 失败', $e->getMessage());
            $this->renderResult();

            return self::FAILURE;
        }

        // ---- 2. getTicker ----
        $this->info(PHP_EOL.'---- 2. 行情连通（getTicker）----');
        try {
            $ticker = $adapter->getTicker($symbol);
            $this->check(true, "getTicker OK: lastPrice={$ticker->lastPrice}");
        } catch (\Throwable $e) {
            $this->check(false, 'getTicker 失败', $e->getMessage());
            $this->renderResult();

            return self::FAILURE;
        }

        // ---- 3. getPosition（空仓）----
        $this->info(PHP_EOL.'---- 3. 初始持仓（getPosition）----');
        try {
            $initPos = $adapter->getPosition($symbol);
            $this->check(true, "初始持仓 qty={$initPos->size} side={$initPos->side}");
        } catch (\Throwable $e) {
            $this->check(false, 'getPosition 失败', $e->getMessage());
            $this->renderResult();

            return self::FAILURE;
        }

        // ---- 4. 真实开仓 ----
        $this->info(PHP_EOL.'---- 4. 真实开仓：BUY MARKET ----');
        try {
            $openOrder = $adapter->placeOrder($symbol, 'BUY', 'MARKET', $qty, null, null, false);
            $this->check($openOrder->status === 'filled',
                "开仓 BUY MARKET 成交：orderId={$openOrder->exchangeOrderId} qty={$openOrder->filledQty} avgPrice={$openOrder->avgFillPrice}",
                "status={$openOrder->status}");
        } catch (\Throwable $e) {
            $this->check(false, '开仓失败', $e->getMessage());
            $this->renderResult();

            return self::FAILURE;
        }

        // ---- 5. getPosition（非空）----
        $this->info(PHP_EOL.'---- 5. 开仓后持仓 ----');
        try {
            $afterOpenPos = $adapter->getPosition($symbol);
            $sizeGt0 = bccomp($afterOpenPos->size, '0', 8) > 0;
            $this->check($sizeGt0 && $afterOpenPos->side === 'long',
                "持仓验证：side={$afterOpenPos->side} size={$afterOpenPos->size} liqPrice={$afterOpenPos->liquidationPrice}");
        } catch (\Throwable $e) {
            $this->check(false, '持仓查询失败', $e->getMessage());
        }

        // ---- 6. 真实平仓（reduceOnly=true）----
        $this->info(PHP_EOL.'---- 6. 真实平仓：SELL MARKET reduceOnly=true ----');
        try {
            $closeQty = $afterOpenPos->size ?? $qty;
            $closeOrder = $adapter->placeOrder($symbol, 'SELL', 'MARKET', $closeQty, null, null, true);
            $this->check($closeOrder->status === 'filled',
                "平仓 SELL MARKET reduceOnly=true 成交：orderId={$closeOrder->exchangeOrderId}");
            // 验证 reduceOnly 确实传给了交易所
            $this->check($closeOrder->reduceOnly === true,
                "reduceOnly 字段验证 reduceOnly={$closeOrder->reduceOnly}");
        } catch (\Throwable $e) {
            $this->check(false, '平仓失败', $e->getMessage());
        }

        // ---- 7. 持仓归零 ----
        $this->info(PHP_EOL.'---- 7. 平仓后持仓（归零）----');
        try {
            $finalPos = $adapter->getPosition($symbol);
            $this->check(bccomp($finalPos->size, '0', 8) === 0,
                "持仓归零验证：size={$finalPos->size}");
        } catch (\Throwable $e) {
            $this->check(false, '最终持仓查询失败', $e->getMessage());
        }

        $this->renderResult();

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(bool $cond, string $label, string $detail = ''): void
    {
        if ($cond) {
            $this->pass++;
            $this->line('  PASS '.$label);
        } else {
            $this->fail++;
            $this->details[] = $label.($detail !== '' ? "（{$detail}）" : '');
            $this->error('  FAIL '.$label.($detail !== '' ? "（{$detail}）" : ''));
        }
    }

    private function renderResult(): void
    {
        $this->newLine();
        $this->info(sprintf('RESULT: %d/%d passed', $this->pass, $this->pass + $this->fail));
        if ($this->fail > 0) {
            $this->error('T047 TESTNET ACCEPTANCE FAILED');
            foreach ($this->details as $d) {
                $this->line('  - '.$d);
            }
        } else {
            $this->info('ALL LIVE CHECKS PASSED — spec 005 真实链路验收通过');
        }
    }
}
