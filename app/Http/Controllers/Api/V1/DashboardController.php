<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\DashboardResource;
use Illuminate\Http\JsonResponse;

/**
 * Dashboard 聚合接口（方案 §7）。
 *
 * 单接口聚合 5 类面板数据：
 *  - market_heat：BTC/ETH tick + 涨跌幅榜 Top 20 + alternative.me 恐惧贪婪指数
 *  - balance：跨交易所 balance_snapshots 多账户汇总
 *  - signal_feed：最近 20 条 signals + running 策略实例
 *  - alert_feed：circuit_breaker_events + exchange_health_checks + 失败订单
 *  - liquidation_map：大户爆仓 Top 5 价格区间聚类
 *
 * 骨架阶段：返回 5 类空数据骨架结构。
 * POC 阶段：注入 DashboardAggregator 服务，并发拉取 + 各子块独立缓存 TTL。
 */
class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        // 骨架占位：POC 阶段填充 DashboardResource::make(DashboardAggregator::aggregate())
        return (new DashboardResource([
            'market_heat' => null,
            'balance' => null,
            'signal_feed' => null,
            'alert_feed' => null,
            'liquidation_map' => null,
            'cached_at' => now()->toIso8601String(),
        ]))->response()->setStatusCode(200);
    }
}
