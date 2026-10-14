<?php

declare(strict_types=1);

namespace App\Domain\Liquidation;

/**
 * 强平密集区聚类的桶宽策略。
 *
 * 设计原则：
 *  - 桶宽按 symbol 配置，约对应典型价格 0.1%（BTC@60000→60，ETH@3000→3，SOL@200→0.2）
 *  - bucketIndex = floor(price / bucketWidth)，整数索引用作 Redis HASH field
 *  - bucketCenter = bucketIndex × bucketWidth（区间左闭右开 [from, to)）
 *  - 配置在 config/liquidation.php 的 `buckets` 节点，支持热更新（重启 worker 生效）
 *
 * 这样设计而非用价格除以固定 0.001 是因为：
 *  1) 每个价格量级的"密集区"语义不同——BTC 60 USDT 桶宽与 ETH 3 USDT 桶宽都对应 0.1%；
 *  2) Redis HASH field 数量可控（每 symbol 通常 < 100 桶），不会爆炸。
 */
class LiquidationBucketPolicy
{
    /**
     * 取 symbol 的桶宽（字符串保留精度）。
     */
    public function bucketWidth(string $symbol): string
    {
        $configured = config("liquidation.buckets.{$symbol}");
        if (is_string($configured) && $configured !== '' && bccomp($configured, '0', 8) === 1) {
            return $configured;
        }

        // 未知 symbol 回退到默认桶宽（避免聚类无桶宽导致除零）
        return (string) config('liquidation.buckets.default', '1');
    }

    /**
     * 取桶索引（floor(price / bucketWidth)）。
     *
     * @param string $price       bcmath 字符串
     * @param string $bucketWidth bcmath 字符串
     */
    public function bucketIndex(string $price, string $bucketWidth): string
    {
        if (bccomp($bucketWidth, '0', 12) <= 0) {
            return '0';
        }

        // bcdiv 第三个参数 0 = 截断为整数（向 0 取整，正数等价 floor）
        return bcdiv($price, $bucketWidth, 0);
    }

    /**
     * 取桶中心价（bucketIndex × bucketWidth）。
     */
    public function bucketCenter(string $bucketIndex, string $bucketWidth): string
    {
        return bcmul($bucketIndex, $bucketWidth, 8);
    }

    /**
     * 取桶价格区间 [from, to)（左闭右开）。
     *
     * @return array{0:string,1:string}
     */
    public function bucketRange(string $bucketIndex, string $bucketWidth): array
    {
        $from = bcmul($bucketIndex, $bucketWidth, 8);

        return [$from, bcadd($from, $bucketWidth, 8)];
    }

    /**
     * 一站式：price → [bucketIndex, bucketCenter, priceFrom, priceTo]。
     *
     * @return array{index:string, center:string, from:string, to:string}
     */
    public function bucketFor(string $symbol, string $price): array
    {
        $bw = $this->bucketWidth($symbol);
        $idx = $this->bucketIndex($price, $bw);
        [$from, $to] = $this->bucketRange($idx, $bw);

        return [
            'index' => $idx,
            'center' => $this->bucketCenter($idx, $bw),
            'from' => $from,
            'to' => $to,
        ];
    }
}
