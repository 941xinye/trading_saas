<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\DashboardResource;
use App\Support\DashboardAggregator;
use Illuminate\Http\JsonResponse;

/**
 * Dashboard 聚合接口（方案 §7）。
 *
 * 单接口聚合 5 类面板数据：
 *  - market_heat：BTC/ETH 最新价 + 24h 涨跌幅（恐惧贪婪指数待接入）
 *  - balance：跨交易所 balance_snapshots 多账户汇总
 *  - signal_feed：最近 20 条 signals + 运行中策略实例
 *  - alert_feed：circuit_breaker_events + 失败订单
 *  - liquidation_map：大户爆仓 Top 5 价格区间聚类
 *
 * 全部数据来自库内/Redis，无外部网络调用；各面板读取均受 TenantScope 隔离。
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardAggregator $aggregator,
    ) {}

    public function index(): JsonResponse
    {
        return (new DashboardResource($this->aggregator->aggregate()))
            ->response()
            ->setStatusCode(200);
    }
}
