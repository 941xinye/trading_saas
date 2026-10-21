<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dashboard 聚合 Resource。
 *
 * 包装方案 §7 的 5 类数据：
 *  - market_heat：市场热度（行情 + 涨跌幅 + 恐惧贪婪）
 *  - balance：余额汇总
 *  - signal_feed：最近信号 + 运行中策略
 *  - alert_feed：熔断事件 + 健康检查 + 失败订单
 *  - liquidation_map：大户爆仓聚类
 *
 * 数据由 DashboardAggregator 聚合；外部行情源缺失的字段允许 null。
 */
class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'market_heat' => $this['market_heat'] ?? null,
            'balance' => $this['balance'] ?? null,
            'signal_feed' => $this['signal_feed'] ?? null,
            'alert_feed' => $this['alert_feed'] ?? null,
            'liquidation_map' => $this['liquidation_map'] ?? null,
            'cached_at' => $this['cached_at'] ?? null,
            'meta' => [
                'version' => 'v1',
            ],
        ];
    }
}
