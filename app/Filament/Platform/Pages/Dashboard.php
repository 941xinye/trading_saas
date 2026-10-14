<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Filament\Platform\Widgets\LiquidationMapWidget;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * 平台超管 Dashboard 骨架。
 *
 * 对应方案 §0 的平台级聚合：租户数 / 活跃策略实例数 / 订单数 / 健康检查。
 * P5 已挂 LiquidationMapWidget（大户爆仓密集区，10s 轮询）。
 * 后续 P6+ 继续：TenantCountWidget / ActiveStrategyInstancesWidget 等。
 */
class Dashboard extends BaseDashboard
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = '平台总览';

    protected static ?int $navigationSort = -2;

    public function getWidgets(): array
    {
        return [
            ...parent::getWidgets(),
            LiquidationMapWidget::class,
        ];
    }
}
