<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Services;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Archive\Dto\HistoricalQueryResult;
use App\Domain\Backtest\Contracts\SimulatedMatcher;
use App\Domain\Backtest\Dto\BacktestRunRequest;
use App\Domain\Backtest\Dto\BacktestRunResult;
use App\Domain\Backtest\Dto\EquityPoint;
use App\Domain\Backtest\Dto\SimulatedTrade;
use App\Domain\Backtest\Exceptions\BacktestDataCoverageException;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\StrategyRegistry;

/**
 * 回测驱动核心（spec 008 T023 / contracts/backtest-runner-contract.md §1）。
 *
 * 纯同步内存执行：不写 DB/Redis、不调任何 ExchangeAdapter（FR-004/FR-005）。
 * 持久化由调用方（RunBacktestJob / backtest:run --sync）负责。
 *
 * 行为契约：
 *   1. 策略构造唯一入口 = StrategyRegistry::make()（FR-003）
 *   2. 回放循环：HistoricalQuery 分页 → 逐 bar onBar → shouldOpen/shouldClose →
 *      attachClientOrderId → matcher.fill → onFill → equity.mark
 *   3. 上下文：每次新建空 StrategyContext(instanceId=0)，不调 restoreState
 *   4. 覆盖率 < config('backtest.min_coverage_pct') → 抛 BacktestDataCoverageException（FR-013）
 */
final class BacktestRunner
{
    private ?\Closure $progressCallback = null;

    public function __construct(
        private readonly HistoricalQuery $historical,
        private readonly StrategyRegistry $registry,
        private readonly SimulatedMatcher $matcher,
        private readonly EquityCurveBuilder $equity,
    ) {
    }

    public function onProgress(?\Closure $cb): self
    {
        $this->progressCallback = $cb;

        return $this;
    }

    /**
     * @throws BacktestDataCoverageException 覆盖率不足
     * @throws \InvalidArgumentException     参数非法（qty<=0 等）
     */
    public function run(BacktestRunRequest $request): BacktestRunResult
    {
        $strategy = $this->registry->make(
            $request->strategyCode,
            0,
            $request->params,
            new StrategyContext(0, [], null, null, [], null),
        );

        $this->equity->reset();

        $trades = [];
        $barCount = 0;
        $cursor = null;
        $minCoverage = (float) config('backtest.min_coverage_pct', 80);

        do {
            $result = $this->historical->query(new HistoricalQueryRequest(
                dataType: ArchiveDataType::KlineBar,
                fromUtc: $request->fromUtc,
                toUtc: $request->toUtc,
                symbol: $request->symbol,
                interval: $request->interval,
                pageSize: $request->pageSize,
                cursor: $cursor,
            ));

            foreach ($result->rows as $row) {
                $bar = $this->toKline($row);
                $barCount++;

                $this->driveBar($strategy, $bar, $request, $trades);
                $this->equity->mark($strategy, $bar);

                if ($this->progressCallback !== null && $barCount % 500 === 0) {
                    ($this->progressCallback)($barCount);
                }
            }

            $cursor = $result->nextPageCursor;
        } while ($cursor !== null);

        $coveragePct = $this->coveragePct($request, $barCount);
        if ((float) $coveragePct < $minCoverage) {
            throw new BacktestDataCoverageException($coveragePct, (string) $minCoverage);
        }

        return new BacktestRunResult(
            barCount: $barCount,
            trades: $trades,
            equityPoints: $this->equity->points(),
            coveragePct: $coveragePct,
        );
    }

    /**
     * 单 bar 驱动：onBar → 决策 → 模拟成交 → onFill。
     *
     * @param SimulatedTrade[] $trades
     */
    private function driveBar(
        StrategyInterface $strategy,
        ExchangeKline $bar,
        BacktestRunRequest $request,
        array &$trades,
    ): void {
        $strategy->onBar($bar);

        $ctx = new StrategyContext(0, [], null, null, [], null);
        $decision = $strategy->shouldOpen($ctx) ?? $strategy->shouldClose($ctx);
        if ($decision === null) {
            return;
        }

        $seq = count($trades) + 1;
        $cid = sprintf('bt_%d_%d', $request->runId ?? 0, $seq);
        $side = (string) ($decision[0] ?? $decision['side'] ?? '');
        $qty = (string) ($decision[1] ?? $decision['qty'] ?? '0');

        if (method_exists($strategy, 'attachClientOrderId')) {
            $strategy->attachClientOrderId($cid, $side, $qty);
        }

        $fill = $this->matcher->fill($decision, $bar, $request->assumptions);
        $this->equity->addFee($fill->fee);

        $prevRealizedPnl = $strategy->getState()->realizedPnl;
        $strategy->onFill($this->filledOrder($cid, $request->symbol, $fill));
        $tradeRealizedPnl = bcsub($strategy->getState()->realizedPnl, $prevRealizedPnl, 8);

        $trades[] = new SimulatedTrade(
            seq: $seq,
            barOpenTime: $fill->barOpenTime,
            side: $fill->side,
            qty: $fill->qty,
            price: $fill->price,
            fee: $fill->fee,
            realizedPnl: $tradeRealizedPnl,
        );
    }

    /**
     * 归档行 → ExchangeKline（open_time 为 UTC 'Y-m-d H:i:s' 字符串）。
     *
     * @param array<string, string|null> $row
     */
    private function toKline(array $row): ExchangeKline
    {
        $openTime = $this->parseTs($row['open_time'] ?? null);
        $closeTime = $this->parseTs($row['close_time'] ?? null);

        return new ExchangeKline(
            exchangeCode: (string) ($row['exchange_code'] ?? ''),
            symbol: (string) ($row['symbol'] ?? ''),
            interval: (string) ($row['interval'] ?? ''),
            openTime: $openTime,
            closeTime: $closeTime,
            open: (string) ($row['open'] ?? '0'),
            high: (string) ($row['high'] ?? '0'),
            low: (string) ($row['low'] ?? '0'),
            close: (string) ($row['close'] ?? '0'),
            volume: (string) ($row['volume'] ?? '0'),
            quoteVolume: (string) ($row['quote_volume'] ?? '0'),
            tradesCount: (int) ($row['trades_count'] ?? 0),
        );
    }

    private function parseTs(?string $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));

            return $dt->getTimestamp() * 1000;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function filledOrder(string $cid, string $symbol, SimulatedTrade $fill): ExchangeOrder
    {
        return new ExchangeOrder(
            exchangeOrderId: 'bt-ex-'.$cid,
            clientOrderId: $cid,
            symbol: $symbol,
            side: $fill->side,
            type: 'market',
            quantity: $fill->qty,
            price: null,
            stopPrice: null,
            leverage: null,
            reduceOnly: false,
            status: 'filled',
            filledQty: $fill->qty,
            avgFillPrice: $fill->price,
            fee: $fill->fee,
            placedAt: $fill->barOpenTime,
            filledAt: $fill->barOpenTime,
        );
    }

    /**
     * 覆盖率 % = 实际 bar 数 / 区间理论 bar 数 × 100。
     */
    private function coveragePct(BacktestRunRequest $request, int $barCount): string
    {
        $intervalSeconds = $this->intervalSeconds($request->interval);
        if ($intervalSeconds <= 0) {
            return '100';
        }

        $rangeSeconds = $request->toUtc->getTimestamp() - $request->fromUtc->getTimestamp();
        $theoretical = intdiv($rangeSeconds, $intervalSeconds);
        if ($theoretical <= 0) {
            return $barCount > 0 ? '100' : '0';
        }

        return bcdiv((string) ($barCount * 100), (string) $theoretical, 2);
    }

    private function intervalSeconds(string $interval): int
    {
        return match ($interval) {
            '1m' => 60,
            '3m' => 180,
            '5m' => 300,
            '15m' => 900,
            '30m' => 1800,
            '1h' => 3600,
            '2h' => 7200,
            '4h' => 14400,
            '6h' => 21600,
            '8h' => 28800,
            '12h' => 43200,
            '1d' => 86400,
            '3d' => 259200,
            '1w' => 604800,
            default => 60,
        };
    }
}
