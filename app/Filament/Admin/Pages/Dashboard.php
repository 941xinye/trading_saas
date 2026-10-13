<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * 租户管理 Dashboard 骨架。
 *
 * 对应方案 §7 的 5 类面板数据：
 *  - 市场热度（MarketHeatService）：BTC/ETH tick + 涨跌幅榜 + 恐惧贪婪指数
 *  - 余额（BalanceAggregator）：跨交易所多账户汇总
 *  - 信号（SignalFeedService）：最近 20 条 + running 策略实例
 *  - 警告（AlertFeedService）：熔断事件 + 健康检查 + 失败订单
 *  - 多空大户爆仓（LiquidationMapService）：Top 5 价格区间聚类
 *
 * 骨架阶段 widget 占位；POC 阶段挂 DashboardAggregator 拉取。
 */
class Dashboard extends BaseDashboard
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = '总览';

    protected static ?int $navigationSort = -2;

    public function getWidgets(): array
    {
        return [
            ...parent::getWidgets(),
        ];
    }
}
