<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Liquidation\LiquidationMapService;
use App\Models\BalanceSnapshot;
use App\Models\CircuitBreakerEvent;
use App\Models\KlineBar;
use App\Models\Signal;
use App\Models\StrategyInstance;
use App\Models\StrategyOrder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Dashboard 聚合器（方案 §7）：单接口聚合 5 类面板数据。
 *
 * 全部取自已落库数据，不做外部网络调用：
 *  - market_heat：全局 K 线最新价 + 24h 涨跌幅（恐惧贪婪指数暂未接入，返回 null）
 *  - balance：每账户最新 balance_snapshot 汇总
 *  - signal_feed：最近 20 条信号 + 运行中实例
 *  - alert_feed：最近熔断事件 + 失败订单
 *  - liquidation_map：BTCUSDT 5m 爆仓密集区 Top 5（Redis 不可用时为空数组）
 */
class DashboardAggregator
{
    private const MARKET_SYMBOLS = ['BTCUSDT', 'ETHUSDT'];

    public function __construct(
        private readonly LiquidationMapService $liquidationMapService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function aggregate(): array
    {
        return [
            'market_heat' => $this->marketHeat(),
            'balance' => $this->balance(),
            'signal_feed' => $this->signalFeed(),
            'alert_feed' => $this->alertFeed(),
            'liquidation_map' => $this->liquidationMap(),
            'cached_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function marketHeat(): array
    {
        $symbols = [];

        foreach (self::MARKET_SYMBOLS as $symbol) {
            $latest = KlineBar::query()
                ->where('symbol', $symbol)
                ->orderByDesc('open_time')
                ->first(['open_time', 'close']);

            if ($latest === null) {
                continue;
            }

            $base = KlineBar::query()
                ->where('symbol', $symbol)
                ->where('close_time', '<=', now()->subDay())
                ->orderByDesc('close_time')
                ->first(['close']);

            $symbols[] = [
                'symbol' => $symbol,
                'last_price' => (string) $latest->close,
                'change_24h_pct' => $base === null
                    ? null
                    : bcmul(bcdiv(bcsub((string) $latest->close, (string) $base->close, 8), (string) $base->close, 8), '100', 2),
                'as_of' => $latest->open_time?->toIso8601String(),
            ];
        }

        return [
            'symbols' => $symbols,
            'top_gainers' => [],
            'fear_greed' => null,
        ];
    }

    /**
     * @return array{total_equity_usdt:string,total_margin_usdt:string,available_usdt:string,unrealized_pnl:string,accounts:array<int,array<string,mixed>>}
     */
    private function balance(): array
    {
        $latestIds = BalanceSnapshot::query()
            ->selectRaw('MAX(id)')
            ->groupBy('exchange_account_id');

        /** @var Collection<int,BalanceSnapshot> $snapshots */
        $snapshots = BalanceSnapshot::query()
            ->with('exchangeAccount')
            ->whereIn('id', $latestIds)
            ->get();

        $totalEquity = '0';
        $totalMargin = '0';
        $available = '0';
        $unrealized = '0';
        $accounts = [];

        foreach ($snapshots as $snapshot) {
            $totalEquity = bcadd($totalEquity, (string) $snapshot->total_equity_usdt, 8);
            $totalMargin = bcadd($totalMargin, (string) $snapshot->total_margin_usdt, 8);
            $available = bcadd($available, (string) $snapshot->available_usdt, 8);
            $unrealized = bcadd($unrealized, (string) $snapshot->unrealized_pnl, 8);

            $accounts[] = [
                'exchange_account_id' => $snapshot->exchange_account_id,
                'label' => $snapshot->exchangeAccount?->label,
                'market' => $snapshot->exchangeAccount?->market,
                'total_equity_usdt' => (string) $snapshot->total_equity_usdt,
                'available_usdt' => (string) $snapshot->available_usdt,
                'snapshot_at' => $snapshot->snapshot_at?->toIso8601String(),
            ];
        }

        return [
            'total_equity_usdt' => $totalEquity,
            'total_margin_usdt' => $totalMargin,
            'available_usdt' => $available,
            'unrealized_pnl' => $unrealized,
            'accounts' => $accounts,
        ];
    }

    /**
     * @return array{signals:array<int,array<string,mixed>>,running_instances:array<int,array<string,mixed>>}
     */
    private function signalFeed(): array
    {
        $signals = Signal::query()
            ->orderByDesc('triggered_at')
            ->limit(20)
            ->get(['id', 'symbol', 'interval', 'direction', 'entry_price', 'confidence', 'source', 'triggered_at'])
            ->map(static fn (Signal $signal): array => [
                'id' => (int) $signal->id,
                'symbol' => $signal->symbol,
                'interval' => $signal->interval,
                'direction' => $signal->direction,
                'entry_price' => (string) $signal->entry_price,
                'confidence' => (string) $signal->confidence,
                'source' => $signal->source,
                'triggered_at' => $signal->triggered_at?->toIso8601String(),
            ])
            ->all();

        $instances = StrategyInstance::query()
            ->whereIn('status', StrategyInstance::LIVE_STATUSES)
            ->orderBy('id')
            ->get(['id', 'strategy_id', 'symbol', 'interval', 'status', 'realized_pnl', 'unrealized_pnl'])
            ->map(static fn (StrategyInstance $instance): array => [
                'id' => (int) $instance->id,
                'strategy_id' => (int) $instance->strategy_id,
                'symbol' => $instance->symbol,
                'interval' => $instance->interval,
                'status' => $instance->status,
                'realized_pnl' => (string) $instance->realized_pnl,
                'unrealized_pnl' => (string) $instance->unrealized_pnl,
            ])
            ->all();

        return ['signals' => $signals, 'running_instances' => $instances];
    }

    /**
     * @return array{breaker_events:array<int,array<string,mixed>>,failed_orders:array<int,array<string,mixed>>}
     */
    private function alertFeed(): array
    {
        $events = CircuitBreakerEvent::query()
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get(['id', 'strategy_instance_id', 'from_state', 'to_state', 'rule_type', 'trigger_value', 'threshold_value', 'occurred_at'])
            ->map(static fn (CircuitBreakerEvent $event): array => [
                'id' => (int) $event->id,
                'strategy_instance_id' => (int) $event->strategy_instance_id,
                'from_state' => $event->from_state,
                'to_state' => $event->to_state,
                'rule_type' => $event->rule_type,
                'trigger_value' => (string) $event->trigger_value,
                'threshold_value' => (string) $event->threshold_value,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])
            ->all();

        $orders = StrategyOrder::query()
            ->whereIn('status', ['rejected', 'failed'])
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'strategy_instance_id', 'symbol', 'side', 'type', 'status', 'error_message', 'created_at'])
            ->map(static fn (StrategyOrder $order): array => [
                'id' => (int) $order->id,
                'strategy_instance_id' => (int) $order->strategy_instance_id,
                'symbol' => $order->symbol,
                'side' => $order->side,
                'type' => $order->type,
                'status' => $order->status,
                'error_message' => $order->error_message,
                'created_at' => $order->created_at?->toIso8601String(),
            ])
            ->all();

        return ['breaker_events' => $events, 'failed_orders' => $orders];
    }

    /**
     * @return array<string, mixed>
     */
    private function liquidationMap(): array
    {
        try {
            return [
                'symbol' => 'BTCUSDT',
                'window' => '5m',
                'clusters' => $this->liquidationMapService->query('binance', 'BTCUSDT', '5m', 5),
            ];
        } catch (Throwable) {
            // Redis 不可用时不阻塞 Dashboard，其它面板照常返回
            return [
                'symbol' => 'BTCUSDT',
                'window' => '5m',
                'clusters' => [],
            ];
        }
    }
}
