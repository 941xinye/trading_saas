<?php

declare(strict_types=1);

namespace App\Filament\Platform\Widgets;

use App\Domain\Liquidation\LiquidationMapService;
use Filament\Widgets\Widget;

/**
 * 大户爆仓密集区 Widget（P5）。
 *
 * 显示指定 symbol + 窗口的 top N 密集区表格，10s 轮询刷新（Livewire）。
 *
 * 数据源：LiquidationMapService::query → LiquidationClusterer::snapshot → Redis HASH 桶聚合。
 * market:feed 进程 B 内 LiquidationFeedRunner 持续写入 Redis。
 *
 * UI 注释：side=SELL 意味着多头被强平；side=BUY 意味着空头被强平。
 */
class LiquidationMapWidget extends Widget
{
    protected string $view = 'filament.platform.widgets.liquidation-map';

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    // 10s 轮询刷新
    protected static ?string $pollInterval = '10s';

    public ?string $filterSymbol = 'BTCUSDT';

    public ?string $filterWindow = '5m';

    public int $filterTopN = 10;

    /** @var string[] */
    public array $symbolOptions = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT'];

    /** @var string[] */
    public array $windowOptions = ['5m', '15m', '60m'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getClustersProperty(): array
    {
        return app(LiquidationMapService::class)->query(
            'binance',
            $this->filterSymbol,
            $this->filterWindow,
            topN: $this->filterTopN,
            currentPrice: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getRecentEventsProperty(): array
    {
        // 最近 5 条事件（从 liquidation_events 表读，给上下文）
        return \Illuminate\Support\Facades\DB::table('liquidation_events')
            ->where('symbol', $this->filterSymbol)
            ->orderByDesc('event_ts')
            ->limit(5)
            ->get()
            ->toArray();
    }
}
