<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * DSL 回测预览结果（spec 006 WS6 T028 / contracts §7 / FR-012）。
 *
 * 复用 P8 HistoricalQuery(KlineBar) 分页回放，与实跑共用同一 DslInterpreter +
 * IndicatorSnapshotBuilder，故信号序列与实跑一致（SC-006）。
 *
 * 资金曲线为「未模拟滑点/手续费」的简易标记估值（realizedPnl + 持仓按当根 close 估值）。
 */
final readonly class DslPreviewResult
{
    /**
     * @param list<array{barTime:int,action:string,qty:string,ruleIndex:int}> $signals
     * @param list<array{barTime:int,equity:string}>                          $equityCurve
     */
    public function __construct(
        public array $signals,
        public array $equityCurve,
        public int $barCount,
        public int $signalCount,
        public string $triggerRate,
        public string $assumption,
        public ?string $nextPageCursor = null,
    ) {}
}
