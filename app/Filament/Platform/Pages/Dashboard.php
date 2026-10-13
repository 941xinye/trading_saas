<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * 平台超管 Dashboard 骨架。
 *
 * 对应方案 §0 的平台级聚合：租户数 / 活跃策略实例数 / 订单数 / 健康检查。
 * 骨架阶段 widget 占位，POC 阶段填充 MarketHeatService 等服务。
 */
class Dashboard extends BaseDashboard
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = '平台总览';

    protected static ?int $navigationSort = -2;

    /**
     * 骨架阶段：暂不挂自定义 widget。
     * POC 阶段在此挂：
     *  - TenantCountWidget
     *  - ActiveStrategyInstancesWidget
     *  - DailyOrdersWidget
     *  - ExchangeHealthWidget
     */
    public function getWidgets(): array
    {
        return [
            ...parent::getWidgets(),
        ];
    }
}
