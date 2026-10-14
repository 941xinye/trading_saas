<?php

declare(strict_types=1);

namespace App\Domain\Liquidation;

/**
 * 强平密集区查询服务。
 *
 * 读 LiquidationClusterer 的 Redis 桶快照，拼装对外暴露的密集区结构。
 *
 * 用法：
 *   Controller/Widget 层先取现价（ExchangeAdapter::getTicker），再调 query() 拼装 distance_pct。
 *   currentPrice 为 null 时 distance_pct = null（仅展示密集区位置，不算上下方位）。
 *
 * 与策略层联动语义：策略消费走本 Service 的 query()，**禁止裸读 Redis ZSET**
 * （耦合 Redis key 演化；Service 是稳定接口）。
 */
class LiquidationMapService
{
    public function __construct(
        private readonly LiquidationClusterer $clusterer,
        private readonly LiquidationBucketPolicy $policy = new LiquidationBucketPolicy(),
    ) {
    }

    /**
     * 查询指定 symbol + 窗口的密集区。
     *
     * @param string      $exchange     binance 等
     * @param string      $symbol       BTCUSDT 等
     * @param string      $window       5m|15m|60m
     * @param int         $topN         返回前 N 个（按 total_qty desc）
     * @param string|null $currentPrice 现价（用于 distance_pct 计算，bcmath 字符串）
     * @param string|null $side         null=同时查 BUY+SELL；'BUY'/'SELL' 单边
     *
     * @return array<int, array{
     *   side:string, price_from:string, price_to:string, bucket_center:string,
     *   total_qty:string, event_count:int, distance_pct:?string
     * }>
     */
    public function query(
        string $exchange,
        string $symbol,
        string $window = '5m',
        int $topN = 10,
        ?string $currentPrice = null,
        ?string $side = null,
    ): array {
        $sides = $side !== null ? [$side] : ['BUY', 'SELL'];
        $rows = [];

        foreach ($sides as $s) {
            $snapshot = $this->clusterer->snapshot($exchange, $symbol, $s, $window);

            foreach ($snapshot as $bucket) {
                $distancePct = null;
                if ($currentPrice !== null && bccomp($currentPrice, '0', 8) === 1) {
                    // distance_pct = (bucket_center - price) / price * 100
                    $diff = bcsub($bucket['center'], $currentPrice, 8);
                    $distancePct = bcmul(bcdiv($diff, $currentPrice, 8), '100', 4);
                }

                $rows[] = [
                    'side' => $s,
                    'price_from' => $bucket['from'],
                    'price_to' => $bucket['to'],
                    'bucket_center' => $bucket['center'],
                    'total_qty' => $bucket['total_qty'],
                    'event_count' => $bucket['event_count'],
                    'distance_pct' => $distancePct,
                ];
            }
        }

        // 按 total_qty desc 排序
        usort($rows, fn ($a, $b) => bccomp($b['total_qty'], $a['total_qty'], 8));

        // topN 截断
        return array_slice($rows, 0, $topN);
    }

    /**
     * 单 side 查询的便捷包装。
     *
     * @return array<int, array<string, mixed>>
     */
    public function querySide(
        string $exchange,
        string $symbol,
        string $side,
        string $window = '5m',
        int $topN = 10,
        ?string $currentPrice = null,
    ): array {
        return $this->query($exchange, $symbol, $window, $topN, $currentPrice, $side);
    }
}
