<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Exchange\Contracts\ExchangeTicker;

/**
 * 解释器求值上下文（contracts §5 / data-model §4.1）。
 *
 * 纯值对象：把 bar/ticker/持仓/预计算指标快照一次性交给白名单解释器，
 * 解释器不触达 IO、不访问交易所、不写库（接口约束）。
 */
final readonly class DslEvalContext
{
    /**
     * @param ExchangeKline[]         $recentBars        升序已闭 bar（指标数据源）
     * @param array<string,mixed>     $indicatorSnapshot IndicatorSnapshotBuilder 输出（now/prev）
     * @param array<string,mixed>|null $positionSnapshot 内存态持仓（qty_pct 平仓换算用）
     */
    public function __construct(
        public int $tenantId,
        public int $instanceId,
        public string $trigger,
        public ?ExchangeKline $bar,
        public ?ExchangeTicker $ticker,
        public array $recentBars,
        public ?ExchangePosition $position,
        public array $indicatorSnapshot,
        public string $allocatedCapital,
        public ?array $positionSnapshot = null,
    ) {}
}
