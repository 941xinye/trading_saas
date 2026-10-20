<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Risk\Contracts\LiquidationPriceCalculator;
use App\Domain\Risk\Contracts\PositionRiskMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * spec 005 T044 — 合约仓位风控模拟命令。
 *
 * 注入模拟仓位（不同保证金率）触发 PositionRiskMonitor.evaluate，
 * 验证三档动作（warning / reduce / emergency_close）并检查 position_risk_events 审计行。
 *
 * 用法：
 *   php artisan poc:futures-risk-simulate --tenant=1 --instance=1
 *   php artisan poc:futures-risk-simulate --tenant=1 --instance=1 --ratio=0.30
 */
class PocFuturesRiskSimulateCommand extends Command
{
    protected $signature = 'poc:futures-risk-simulate
        {--tenant= : 租户 ID（默认取首个）}
        {--instance= : 策略实例 ID（默认取首个 futures_grid）}
        {--symbol=BTCUSDT : 交易对}
        {--entry=100 : 开仓均价}
        {--size=1 : 持仓数量}
        {--leverage=10 : 杠杆}
        {--ratio= : 指定保证金率（0~1），不指定则依次模拟三档}
        {--side=long : 持仓方向 long|short}';

    protected $description = 'POC: simulate position risk evaluation and verify 3-tier actions';

    public function handle(PositionRiskMonitor $monitor, LiquidationPriceCalculator $calc): int
    {
        $tenantId = $this->option('tenant')
            ? (int) $this->option('tenant')
            : (int) DB::table('tenants')->value('id');
        $instanceId = $this->option('instance')
            ? (int) $this->option('instance')
            : (int) DB::table('strategy_instances')
                ->join('strategies', 'strategies.id', '=', 'strategy_instances.strategy_id')
                ->where('strategies.code', 'futures_grid')
                ->value('strategy_instances.id');

        if ($tenantId === 0 || $instanceId === 0) {
            $this->error('No tenant or futures_grid instance found. Run poc:futures-testnet-setup first.');

            return self::FAILURE;
        }

        $symbol = (string) $this->option('symbol');
        $entry = (string) $this->option('entry');
        $size = (string) $this->option('size');
        $leverage = (int) $this->option('leverage');
        $side = (string) $this->option('side');
        $mmr = (string) (config("risk.position.default_mmr.{$symbol}") ?? '0.0050');
        $liqPrice = $calc->estimate($entry, $leverage, $mmr, $side);

        $this->info("Simulating position risk for tenant={$tenantId} instance={$instanceId}");
        $this->line("  symbol={$symbol} side={$side} entry={$entry} size={$size} leverage={$leverage}x");
        $this->line("  MMR={$mmr} estimated liquidation price={$liqPrice}");

        // 检查 Redis 可用性
        $redisOk = true;
        try {
            Redis::connection('default')->ping();
        } catch (\Throwable) {
            $redisOk = false;
            $this->warn('Redis unavailable — reduce/emergency actions will be skipped (lock unavailable)');
        }

        $ratios = $this->option('ratio')
            ? [(float) $this->option('ratio')]
            : [0.80, 0.50, 0.30, 0.15, 0.05];

        foreach ($ratios as $ratio) {
            $markPrice = $this->priceForRatio($entry, $liqPrice, $ratio, $side);
            $position = new ExchangePosition(
                symbol: $symbol,
                side: $side,
                size: $size,
                entryPrice: $entry,
                markPrice: $markPrice,
                liquidationPrice: $liqPrice,
                leverage: $leverage,
                unrealizedPnl: bcmul(bcsub($markPrice, $entry, 8), $size, 8),
                margin: bcdiv(bcmul($entry, $size, 8), (string) $leverage, 8),
            );

            $decision = $monitor->evaluate($tenantId, $instanceId, $symbol, $position, $markPrice);

            $action = $decision?->actionType ?? 'null';
            $this->line(sprintf(
                '  ratio=%.2f mark=%s → %s%s',
                $ratio,
                $markPrice,
                $action,
                $decision?->orderQty ? " qty={$decision->orderQty}" : '',
            ));
        }

        // 审计行统计
        $eventCount = DB::table('position_risk_events')
            ->where('tenant_id', $tenantId)
            ->where('strategy_instance_id', $instanceId)
            ->count();
        $this->info(PHP_EOL."position_risk_events rows for this instance: {$eventCount}");

        if ($eventCount > 0) {
            $rows = DB::table('position_risk_events')
                ->where('tenant_id', $tenantId)
                ->where('strategy_instance_id', $instanceId)
                ->orderByDesc('id')
                ->limit(5)
                ->get(['action_type', 'trigger_reason', 'margin_ratio', 'mark_price', 'status', 'created_at']);

            $this->table(
                ['action', 'reason', 'margin_ratio', 'mark_price', 'status', 'created_at'],
                $rows->map(fn ($r) => [
                    $r->action_type,
                    $r->trigger_reason,
                    $r->margin_ratio,
                    $r->mark_price,
                    $r->status,
                    $r->created_at,
                ])->all(),
            );
        }

        if (! $redisOk) {
            $this->warn(PHP_EOL.'Note: reduce/emergency actions require Redis single-flight lock. Start Redis to test full 3-tier flow.');
        }

        return self::SUCCESS;
    }

    /**
     * 反推达到指定保证金率的 markPrice。
     */
    private function priceForRatio(string $entry, string $liq, float $ratio, string $side): string
    {
        if ($side === 'long') {
            return bcadd($liq, bcmul((string) $ratio, bcsub($entry, $liq, 8), 8), 8);
        }

        return bcsub($liq, bcmul((string) $ratio, bcsub($liq, $entry, 8), 8), 8);
    }
}
