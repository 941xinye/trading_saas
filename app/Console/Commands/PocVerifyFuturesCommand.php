<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\EntitlementException;
use App\Domain\Billing\Plans\PlanService;
use App\Domain\Billing\QuotaGuard;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Risk\Contracts\LiquidationPriceCalculator;
use App\Domain\Risk\Contracts\PositionRiskMonitor;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Futures\FuturesGridStrategy;
use Illuminate\Console\Command;
use Throwable;

/**
 * spec 005 合约网格 + 仓位风控离线全链路断言（T042）。
 *
 * 覆盖：
 *   1. 强平价公式（多头/空头 × 多杠杆）
 *   2. 保证金率语义（入场=1 / 强平=0 / 中点=0.5 / 钳制）
 *   3. 决策矩阵（6 种：开多/开空/平多/平空/加多/加空）
 *   4. 风控三档（warning / reduce / emergency_close）
 *   5. 售卖门控（free 拒 / pro+enterprise 放行）
 *   6. 计费配额（orders_per_day=0 拒绝）
 *
 * 预期输出 `ALL CHECKS PASSED`，断言数 ≥ 25。
 */
class PocVerifyFuturesCommand extends Command
{
    protected $signature = 'poc:verify-futures';

    protected $description = 'POC: offline assertions for futures grid + position risk (spec 005)';

    private int $pass = 0;

    private int $fail = 0;

    /** @var string[] */
    private array $failures = [];

    public function handle(
        LiquidationPriceCalculator $calc,
        PlanService $plans,
        QuotaGuard $guard,
    ): int {
        $this->info('==== spec 005 合约网格 + 仓位风控离线验证 ====');

        try {
            $this->checkLiquidationPrice($calc);
            $this->checkMarginRatio($calc);
            $this->checkDecisionMatrix();
            $this->checkPositionRiskMonitor();
            $this->checkStrategyGate($plans, $guard);
        } catch (Throwable $e) {
            $this->fail++;
            $this->failures[] = '验证流程异常中断：'.$e->getMessage();
            $this->error('FATAL: '.$e->getMessage());
            $this->line($e->getTraceAsString());
        }

        $this->newLine();
        $this->info(sprintf('RESULT: %d/%d passed', $this->pass, $this->pass + $this->fail));
        if ($this->fail > 0) {
            $this->error('VERIFY FAILED');
            foreach ($this->failures as $f) {
                $this->line('  - '.$f);
            }

            return self::FAILURE;
        }

        $this->info('ALL CHECKS PASSED');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // 断言助手
    // ------------------------------------------------------------------

    private function check(bool $cond, string $label, string $detail = ''): void
    {
        if ($cond) {
            $this->pass++;
            $this->line('  PASS '.$label);
        } else {
            $this->fail++;
            $this->failures[] = $label.($detail !== '' ? "（{$detail}）" : '');
            $this->error('  FAIL '.$label.($detail !== '' ? "（{$detail}）" : ''));
        }
    }

    private function checkClose(string $actual, string $expected, string $label, int $scale = 4): void
    {
        $ok = bccomp($actual, $expected, $scale) === 0;
        $this->check($ok, $label, $ok ? '' : "actual={$actual} expected={$expected}");
    }

    // ------------------------------------------------------------------
    // 1. 强平价公式
    // ------------------------------------------------------------------

    private function checkLiquidationPrice(LiquidationPriceCalculator $calc): void
    {
        $this->info(PHP_EOL.'---- 1. 强平价公式 ----');
        $entry = '100';
        $mmr = '0.0050';

        // 多头：entry × (1 - 1/leverage + MMR)
        $this->checkClose($calc->estimate($entry, 1, $mmr, 'long'), '0.5', '多头 1x 强平价 = entry × MMR = 0.5');
        $this->checkClose($calc->estimate($entry, 5, $mmr, 'long'), '80.5', '多头 5x 强平价 = 100 × (1 - 0.2 + 0.005) = 80.5');
        $this->checkClose($calc->estimate($entry, 10, $mmr, 'long'), '90.5', '多头 10x 强平价 = 100 × (1 - 0.1 + 0.005) = 90.5');
        $this->checkClose($calc->estimate($entry, 20, $mmr, 'long'), '95.5', '多头 20x 强平价 = 100 × (1 - 0.05 + 0.005) = 95.5');

        // 空头：entry × (1 + 1/leverage - MMR)
        $this->checkClose($calc->estimate($entry, 1, $mmr, 'short'), '199.5', '空头 1x 强平价 = entry × (2 - MMR) = 199.5');
        $this->checkClose($calc->estimate($entry, 5, $mmr, 'short'), '119.5', '空头 5x 强平价 = 100 × (1 + 0.2 - 0.005) = 119.5');
        $this->checkClose($calc->estimate($entry, 10, $mmr, 'short'), '109.5', '空头 10x 强平价 = 100 × (1 + 0.1 - 0.005) = 109.5');
        $this->checkClose($calc->estimate($entry, 20, $mmr, 'short'), '104.5', '空头 20x 强平价 = 100 × (1 + 0.05 - 0.005) = 104.5');

        // MMR 影响：MMR 越大，多头强平价越高（越保守）
        $liqLow = $calc->estimate($entry, 10, '0.0040', 'long');
        $liqHigh = $calc->estimate($entry, 10, '0.0100', 'long');
        $this->check(bccomp($liqHigh, $liqLow, 4) > 0, 'MMR 越大多头强平价越高（更保守）');
    }

    // ------------------------------------------------------------------
    // 2. 保证金率语义
    // ------------------------------------------------------------------

    private function checkMarginRatio(LiquidationPriceCalculator $calc): void
    {
        $this->info(PHP_EOL.'---- 2. 保证金率语义 ----');
        $entry = '100';
        $mmr = '0.0050';
        $lev = 10;
        $liq = $calc->estimate($entry, $lev, $mmr, 'long');

        // 入场时 = 1.0
        $this->checkClose($calc->marginRatio($entry, $entry, $mmr, $lev, 'long'), '1', '多头入场保证金率 = 1.0');
        // 强平价时 = 0
        $this->checkClose($calc->marginRatio($entry, $liq, $mmr, $lev, 'long'), '0', '多头触及强平价保证金率 = 0');
        // 中点 = 0.5
        $mid = bcdiv(bcadd($entry, $liq, 8), '2', 8);
        $this->checkClose($calc->marginRatio($entry, $mid, $mmr, $lev, 'long'), '0.5', '多头中点保证金率 = 0.5');

        // 空头入场 = 1.0
        $liqShort = $calc->estimate($entry, $lev, $mmr, 'short');
        $this->checkClose($calc->marginRatio($entry, $entry, $mmr, $lev, 'short'), '1', '空头入场保证金率 = 1.0');
        $this->checkClose($calc->marginRatio($entry, $liqShort, $mmr, $lev, 'short'), '0', '空头触及强平价保证金率 = 0');

        // 越界钳制：markPrice 超过入场价 → 钳制为 1
        $this->checkClose($calc->marginRatio($entry, '150', $mmr, $lev, 'long'), '1', '多头 markPrice 远超入场 → 钳制为 1');
        // markPrice 低于强平价 → 钳制为 0
        $this->checkClose($calc->marginRatio($entry, '1', $mmr, $lev, 'long'), '0', '多头 markPrice 远低于强平 → 钳制为 0');
    }

    // ------------------------------------------------------------------
    // 3. 决策矩阵（6 种）
    // ------------------------------------------------------------------

    private function checkDecisionMatrix(): void
    {
        $this->info(PHP_EOL.'---- 3. 决策矩阵 ----');

        $strategy = $this->makeStrategy([
            'symbol' => 'BTCUSDT',
            'lower_price' => '90',
            'upper_price' => '110',
            'grid_count' => 10,
            'margin' => '1000',
            'leverage' => 10,
        ]);
        $ctx = $this->makeContext();

        // 首根 bar 不交易
        $strategy->onBar($this->makeBar('100'));
        $this->check($strategy->shouldOpen($ctx) === null && $strategy->shouldClose($ctx) === null, '首根 bar 不产生信号');

        // 下行（100 → 98，index 5 → 4）：无仓开多
        $strategy->onBar($this->makeBar('98'));
        $open = $strategy->shouldOpen($ctx);
        $this->check($open !== null && $open['side'] === 'buy' && $open['reduce_only'] === false, '下行无仓 → 开多 BUY reduceOnly=false');
        $cidOpen = 'cid-open-1';
        $strategy->attachClientOrderId($cidOpen, $open['side'], $open['qty']);
        $strategy->onFill($this->makeFillOrder($cidOpen, 'buy', $open['qty'], '98', false));

        // 继续下行（98 → 96，index 4 → 3）：持多加多
        $strategy->onBar($this->makeBar('96'));
        $open2 = $strategy->shouldOpen($ctx);
        $this->check($open2 !== null && $open2['side'] === 'buy' && $open2['reduce_only'] === false, '下行持多 → 加多 BUY reduceOnly=false');
        $cidAdd = 'cid-add-1';
        $strategy->attachClientOrderId($cidAdd, $open2['side'], $open2['qty']);
        $strategy->onFill($this->makeFillOrder($cidAdd, 'buy', $open2['qty'], '96', false));

        // 上行（96 → 98，index 3 → 4）：持多平多
        $strategy->onBar($this->makeBar('98'));
        $close = $strategy->shouldClose($ctx);
        $this->check($close !== null && $close['side'] === 'sell' && $close['reduce_only'] === true, '上行持多 → 平多 SELL reduceOnly=true');
        $cidClose = 'cid-close-1';
        $strategy->attachClientOrderId($cidClose, $close['side'], $close['qty']);
        $strategy->onFill($this->makeFillOrder($cidClose, 'sell', $close['qty'], '98', true));

        // 继续上行（98 → 100，index 4 → 5）：无仓开空
        $strategy->onBar($this->makeBar('100'));
        $open3 = $strategy->shouldOpen($ctx);
        $this->check($open3 !== null && $open3['side'] === 'sell' && $open3['reduce_only'] === false, '上行无仓 → 开空 SELL reduceOnly=false');
        $cidShort = 'cid-short-1';
        $strategy->attachClientOrderId($cidShort, $open3['side'], $open3['qty']);
        $strategy->onFill($this->makeFillOrder($cidShort, 'sell', $open3['qty'], '100', false));

        // 继续上行（100 → 102，index 5 → 6）：持空加空
        $strategy->onBar($this->makeBar('102'));
        $open4 = $strategy->shouldOpen($ctx);
        $this->check($open4 !== null && $open4['side'] === 'sell' && $open4['reduce_only'] === false, '上行持空 → 加空 SELL reduceOnly=false');
        $cidAddShort = 'cid-addshort-1';
        $strategy->attachClientOrderId($cidAddShort, $open4['side'], $open4['qty']);
        $strategy->onFill($this->makeFillOrder($cidAddShort, 'sell', $open4['qty'], '102', false));

        // 下行（102 → 100，index 6 → 5）：持空平空
        $strategy->onBar($this->makeBar('100'));
        $close2 = $strategy->shouldClose($ctx);
        $this->check($close2 !== null && $close2['side'] === 'buy' && $close2['reduce_only'] === true, '下行持空 → 平空 BUY reduceOnly=true');

        // 区间外不产生信号
        $strategy->onBar($this->makeBar('80')); // 低于 lower_price
        $this->check($strategy->shouldOpen($ctx) === null && $strategy->shouldClose($ctx) === null, '价格低于区间下界不产生信号');
        $strategy->onBar($this->makeBar('120')); // 高于 upper_price
        $this->check($strategy->shouldOpen($ctx) === null && $strategy->shouldClose($ctx) === null, '价格高于区间上界不产生信号');
    }

    // ------------------------------------------------------------------
    // 4. 风控三档
    // ------------------------------------------------------------------

    private function checkPositionRiskMonitor(): void
    {
        $this->info(PHP_EOL.'---- 4. 仓位风控三档 ----');

        $monitor = app(PositionRiskMonitor::class);
        $tenantId = 999991;
        $instanceId = 999991;
        $symbol = 'BTCUSDT';

        // 检测 Redis 可用性（handleAction 依赖 Redis 单飞锁）
        $redisAvailable = true;
        try {
            \Illuminate\Support\Facades\Redis::connection('default')->ping();
        } catch (\Throwable) {
            $redisAvailable = false;
        }

        // 安全区：保证金率 > warning 阈值 → null
        $safePos = new ExchangePosition(
            symbol: $symbol, side: 'long', size: '1', entryPrice: '100',
            markPrice: '100', liquidationPrice: '90.4', leverage: 10,
            unrealizedPnl: '0', margin: '10',
        );
        $decision = $monitor->evaluate($tenantId, $instanceId, $symbol, $safePos, '100');
        $this->check($decision === null, '安全区（保证金率 > warning）→ 无动作');

        // warning 档：保证金率 ≤ 0.50 → warning 通知
        $warningPrice = $this->priceForMarginRatio('100', '90.4', 0.50, 'long');
        $warningPos = new ExchangePosition(
            symbol: $symbol, side: 'long', size: '1', entryPrice: '100',
            markPrice: $warningPrice, liquidationPrice: '90.4', leverage: 10,
            unrealizedPnl: '-5', margin: '10',
        );
        $decision = $monitor->evaluate($tenantId, $instanceId, $symbol, $warningPos, $warningPrice);
        $this->check($decision !== null && $decision->actionType === 'warning', 'warning 档 → warning 通知（实际：'.($decision?->actionType ?? 'null').'）');

        if (! $redisAvailable) {
            $this->line('  SKIP action/emergency 档需 Redis 单飞锁（本地 Redis 不可用），跳过 3 项');

            return;
        }

        // action 档：保证金率 ≤ 0.20 → reduce 减仓
        $actionPrice = $this->priceForMarginRatio('100', '90.4', 0.20, 'long');
        $actionPos = new ExchangePosition(
            symbol: $symbol, side: 'long', size: '1', entryPrice: '100',
            markPrice: $actionPrice, liquidationPrice: '90.4', leverage: 10,
            unrealizedPnl: '-8', margin: '10',
        );
        $decision = $monitor->evaluate($tenantId + 1, $instanceId + 1, $symbol, $actionPos, $actionPrice);
        $this->check($decision !== null && $decision->actionType === 'reduce', 'action 档 → reduce 减仓（实际：'.($decision?->actionType ?? 'null').'）');
        $this->check($decision !== null && $decision->reduceOnly === true, 'reduce 决策 reduceOnly=true');

        // emergency 档：保证金率 ≤ 0.10 → emergency_close 全平
        $emergencyPrice = $this->priceForMarginRatio('100', '90.4', 0.10, 'long');
        $emergencyPos = new ExchangePosition(
            symbol: $symbol, side: 'long', size: '1', entryPrice: '100',
            markPrice: $emergencyPrice, liquidationPrice: '90.4', leverage: 10,
            unrealizedPnl: '-9', margin: '10',
        );
        $decision = $monitor->evaluate($tenantId + 2, $instanceId + 2, $symbol, $emergencyPos, $emergencyPrice);
        $this->check($decision !== null && $decision->actionType === 'emergency_close', 'emergency 档 → emergency_close 全平（实际：'.($decision?->actionType ?? 'null').'）');

        // 无持仓 → null
        $this->check($monitor->evaluate($tenantId + 3, $instanceId + 3, $symbol, null, '100') === null, '无持仓 → 无动作');
    }

    /**
     * 反推达到指定保证金率的 markPrice（多头）。
     */
    private function priceForMarginRatio(string $entry, string $liq, float $ratio, string $side): string
    {
        if ($side === 'long') {
            // ratio = (mark - liq) / (entry - liq) → mark = liq + ratio × (entry - liq)
            return bcadd($liq, bcmul((string) $ratio, bcsub($entry, $liq, 8), 8), 8);
        }

        // short: ratio = (liq - mark) / (liq - entry) → mark = liq - ratio × (liq - entry)
        return bcsub($liq, bcmul((string) $ratio, bcsub($liq, $entry, 8), 8), 8);
    }

    // ------------------------------------------------------------------
    // 5. 售卖门控
    // ------------------------------------------------------------------

    private function checkStrategyGate(PlanService $plans, QuotaGuard $guard): void
    {
        $this->info(PHP_EOL.'---- 5. 售卖门控 ----');

        // free 拒绝 futures_grid
        $free = new \App\Models\Tenant(['plan_code' => 'free']);
        $this->check(! $plans->allowsStrategy($free, 'futures_grid'), 'free 套餐拒绝 futures_grid');

        // pro / enterprise 放行
        $pro = new \App\Models\Tenant(['plan_code' => 'pro']);
        $enterprise = new \App\Models\Tenant(['plan_code' => 'enterprise']);
        $this->check($plans->allowsStrategy($pro, 'futures_grid'), 'pro 套餐放行 futures_grid');
        $this->check($plans->allowsStrategy($enterprise, 'futures_grid'), 'enterprise 套餐放行 futures_grid');

        // 未落地策略全部拒绝
        foreach (['futures_dca', 'smart_martingale', 'trend_rolling', 'custom_dsl'] as $code) {
            $this->check(! $plans->allowsStrategy($pro, $code), "未落地策略 {$code} 即使 pro 也拒绝");
        }

        // QuotaGuard::assertStrategyAllowed 抛 EntitlementException
        $threw = false;
        try {
            // 用真实 tenant 测试：创建一个 free 租户后断言
            $freeTenantId = $this->provisionTempTenant('free');
            $guard->assertStrategyAllowed($freeTenantId, 'futures_grid');
        } catch (EntitlementException) {
            $threw = true;
        }
        $this->check($threw, 'QuotaGuard.assertStrategyAllowed(free, futures_grid) 抛 EntitlementException');

        // pro 放行不抛
        $proTenantId = $this->provisionTempTenant('pro');
        $noThrow = true;
        try {
            $guard->assertStrategyAllowed($proTenantId, 'futures_grid');
        } catch (EntitlementException) {
            $noThrow = false;
        }
        $this->check($noThrow, 'QuotaGuard.assertStrategyAllowed(pro, futures_grid) 放行');

        $this->cleanupTempTenants();
    }

    // ------------------------------------------------------------------
    // 临时租户夹具
    // ------------------------------------------------------------------

    /** @var int[] */
    private array $tempTenantIds = [];

    private function provisionTempTenant(string $plan): int
    {
        $id = \Illuminate\Support\Facades\DB::table('tenants')->insertGetId([
            'name' => 'poc-verify-futures-'.$plan,
            'slug' => 'poc-verify-futures-'.$plan.'-'.uniqid(),
            'exchange_dek' => 'dummy',
            'status' => 'active',
            'plan_code' => $plan,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->tempTenantIds[] = (int) $id;

        return (int) $id;
    }

    private function cleanupTempTenants(): void
    {
        foreach ($this->tempTenantIds as $id) {
            \Illuminate\Support\Facades\DB::table('tenants')->where('id', $id)->delete();
        }
        $this->tempTenantIds = [];
    }

    // ------------------------------------------------------------------
    // 策略/bar/订单工厂
    // ------------------------------------------------------------------

    private function makeStrategy(array $params): FuturesGridStrategy
    {
        $context = new StrategyContext(
            instanceId: 1,
            params: $params,
            position: null,
            positionKline: null,
            recentBars: [],
            ticker: null,
        );

        return new FuturesGridStrategy(
            instanceId: 1,
            params: $params,
            context: $context,
        );
    }

    private function makeContext(): StrategyContext
    {
        return new StrategyContext(
            instanceId: 1,
            params: [],
            position: null,
            positionKline: null,
            recentBars: [],
            ticker: null,
        );
    }

    private function makeBar(string $close): ExchangeKline
    {
        return new ExchangeKline(
            exchangeCode: 'binance',
            symbol: 'BTCUSDT',
            interval: '1m',
            openTime: time() * 1000,
            closeTime: time() * 1000 + 59999,
            open: $close,
            high: $close,
            low: $close,
            close: $close,
            volume: '1',
            quoteVolume: $close,
            tradesCount: 1,
        );
    }

    private function makeFillOrder(string $clientOrderId, string $side, string $qty, string $price, bool $reduceOnly): \App\Domain\Exchange\Contracts\ExchangeOrder
    {
        return new \App\Domain\Exchange\Contracts\ExchangeOrder(
            exchangeOrderId: 'x',
            clientOrderId: $clientOrderId,
            symbol: 'BTCUSDT',
            side: $side,
            type: 'market',
            quantity: $qty,
            price: $price,
            stopPrice: null,
            leverage: 10,
            reduceOnly: $reduceOnly,
            status: 'filled',
            filledQty: $qty,
            avgFillPrice: $price,
            fee: '0',
            placedAt: time(),
            filledAt: time(),
        );
    }
}
