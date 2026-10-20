<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl\Preview;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Custom\Dsl\DslPreviewResult;
use App\Domain\Strategy\Custom\Dsl\DslPreviewService;
use App\Domain\Strategy\Custom\Dsl\DslValidationException;
use App\Domain\Strategy\Custom\DslParser;
use App\Domain\Strategy\Custom\DslStrategy;

/**
 * 历史 K 线 DSL 预览实现（spec 006 WS6 T028 / FR-012 / SC-006）。
 *
 * 复用 P8 HistoricalQuery(KlineBar) 分页拉取 [from, to) 区间已闭 K 线，逐 bar 驱动
 * DslStrategy（与实跑共用同一 WhitelistInterpreter + IndicatorSnapshotBuilder + bcmath
 * 数量换算），产出信号序列与简易资金曲线。
 *
 * 分页：`pageSize` 取 config `archive.query_page_size`（默认 1000）；游标续页直到无更多。
 * 资金曲线：realizedPnl + 持仓按当根 close 标记估值（多头 +、空头 -），注明未模拟滑点/手续费。
 */
final class HistoricalPreviewService implements DslPreviewService
{
    private const DEFAULT_CAPITAL = '10000';

    private const ASSUMPTION = '未模拟滑点/手续费';

    public function __construct(
        private readonly HistoricalQuery $historical,
        private readonly ?DslParser $parser = null,
        private readonly ?string $defaultCapital = null,
    ) {}

    public function preview(
        int $tenantId,
        string $script,
        string $symbol,
        string $interval,
        \DateTimeImmutable $fromUtc,
        \DateTimeImmutable $toUtc,
        ?int $pageSize = null,
    ): DslPreviewResult {
        $program = ($this->parser ?? new DslParser())->parse($script);

        $strategy = new DslStrategy(
            0,
            [
                'dsl_script' => $script,
                'symbol' => $symbol,
                'tenant_id' => $tenantId,
                'allocated_capital' => $this->defaultCapital ?? self::DEFAULT_CAPITAL,
            ],
            new StrategyContext(0, [], null, null, [], null),
            $this->parser,
        );

        $signals = [];
        $equityCurve = [];
        $barCount = 0;
        $cursor = null;

        do {
            $request = new HistoricalQueryRequest(
                dataType: ArchiveDataType::KlineBar,
                fromUtc: $fromUtc,
                toUtc: $toUtc,
                symbol: $symbol,
                interval: $interval,
                pageSize: $pageSize,
                cursor: $cursor,
            );

            $result = $this->historical->query($request);

            foreach ($result->rows as $row) {
                $bar = $this->toKline($row);
                $signal = $this->driveBar($strategy, $bar, $barCount);
                $barCount++;

                if ($signal !== null) {
                    $signals[] = $signal;
                }

                $equityCurve[] = [
                    'barTime' => $bar->openTime,
                    'equity' => $this->equity($strategy, $bar->close),
                ];
            }

            $cursor = $result->nextPageCursor;
        } while ($cursor !== null);

        $signalCount = count($signals);
        $triggerRate = $barCount > 0
            ? bcdiv((string) $signalCount, (string) $barCount, 8)
            : '0';

        return new DslPreviewResult(
            signals: $signals,
            equityCurve: $equityCurve,
            barCount: $barCount,
            signalCount: $signalCount,
            triggerRate: $triggerRate,
            assumption: self::ASSUMPTION,
            nextPageCursor: null,
        );
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    /**
     * 驱动单 bar：onBar → 捕获 pendingSignal(action/ruleIndex) → shouldOpen/shouldClose →
     * attachCid → 模拟成交 onFill（按 bar.close 成交，无滑点）。
     *
     * @return array{barTime:int,action:string,qty:string,ruleIndex:int}|null
     */
    private function driveBar(DslStrategy $strategy, ExchangeKline $bar, int $seq): ?array
    {
        $strategy->onBar($bar);

        // 捕获信号元数据（shouldOpen 消费前），用于记录 action/ruleIndex
        $meta = $this->readPendingSignal($strategy);

        $ctx = new StrategyContext(0, [], null, null, [], null);
        $decision = $strategy->shouldOpen($ctx) ?? $strategy->shouldClose($ctx);
        if ($decision === null) {
            return null;
        }

        $cid = 'preview-'.$seq;
        $strategy->attachClientOrderId($cid, (string) $decision['side'], (string) $decision['qty']);
        $strategy->onFill($this->filledOrder($cid, (string) $decision['side'], (string) $decision['qty'], $bar->close));

        return [
            'barTime' => $bar->openTime,
            'action' => $meta['action'] ?? ($decision['side'] === 'buy' ? 'open_long' : 'close_long'),
            'qty' => (string) $decision['qty'],
            'ruleIndex' => (int) ($meta['rule_index'] ?? 0),
        ];
    }

    /**
     * 读取 DslStrategy 内存态 pending_signal（shouldOpen 消费前）。
     *
     * @return array<string,mixed>|null
     */
    private function readPendingSignal(DslStrategy $strategy): ?array
    {
        return $strategy->pendingSignal();
    }

    /**
     * 简易资金曲线：realizedPnl + 持仓按当根 close 标记估值。
     */
    private function equity(DslStrategy $strategy, string $close): string
    {
        $state = $strategy->getState();
        $pos = is_array($state->positionSnapshot['position'] ?? null)
            ? $state->positionSnapshot['position']
            : ['qty' => '0', 'direction' => null, 'entry_price' => null];

        $qty = (string) ($pos['qty'] ?? '0');
        $direction = $pos['direction'] ?? null;
        $entry = (string) ($pos['entry_price'] ?? '0');

        $unrealized = '0';
        if (bccomp($qty, '0', 8) === 1) {
            if ($direction === 'short') {
                $unrealized = bcmul($qty, bcsub($entry, $close, 12), 12);
            } else {
                $unrealized = bcmul($qty, bcsub($close, $entry, 12), 12);
            }
        }

        return bcadd($state->realizedPnl, $unrealized, 12);
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

    private function filledOrder(string $cid, string $side, string $qty, string $price): ExchangeOrder
    {
        return new ExchangeOrder(
            exchangeOrderId: 'preview-ex-'.$cid,
            clientOrderId: $cid,
            symbol: 'BTCUSDT',
            side: $side,
            type: 'market',
            quantity: $qty,
            price: null,
            stopPrice: null,
            leverage: null,
            reduceOnly: false,
            status: 'filled',
            filledQty: $qty,
            avgFillPrice: $price,
            fee: '0',
            placedAt: 0,
            filledAt: 0,
        );
    }
}
