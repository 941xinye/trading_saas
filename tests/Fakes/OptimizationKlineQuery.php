<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Archive\Dto\HistoricalQueryResult;

/**
 * 参数寻优测试用 K 线源：按请求区间逐分钟生成确定性震荡行情。
 *
 * failFirstQuery = true 时第一次查询抛错，用于验证单组合失败不中断整体寻优。
 */
final class OptimizationKlineQuery implements HistoricalQuery
{
    private bool $failedOnce = false;

    public function __construct(
        private readonly bool $failFirstQuery = false,
    ) {}

    public function query(HistoricalQueryRequest $request): HistoricalQueryResult
    {
        if ($this->failFirstQuery && ! $this->failedOnce) {
            $this->failedOnce = true;

            throw new \RuntimeException('fixture: 首次查询失败');
        }

        $start = $request->fromUtc->getTimestamp();
        $end = $request->toUtc->getTimestamp();

        $rows = [];
        $index = 0;
        for ($ts = $start; $ts < $end; $ts += 60, $index++) {
            // 100~114 往返，跨越现货网格 90~110 区间的多档格子
            $price = 100 + (($index % 8) * 2);

            $rows[] = [
                'id' => (string) ($index + 1),
                'exchange_code' => 'binance',
                'symbol' => $request->symbol ?? 'BTCUSDT',
                'interval' => $request->interval ?? '1m',
                'open_time' => (new \DateTimeImmutable('@'.$ts))->format('Y-m-d H:i:s'),
                'close_time' => (new \DateTimeImmutable('@'.($ts + 59)))->format('Y-m-d H:i:s'),
                'open' => (string) $price,
                'high' => (string) ($price + 1),
                'low' => (string) ($price - 1),
                'close' => (string) $price,
                'volume' => '1',
                'quote_volume' => '1',
                'trades_count' => '1',
            ];
        }

        return new HistoricalQueryResult($rows, null, HistoricalQueryResult::HOT);
    }
}
