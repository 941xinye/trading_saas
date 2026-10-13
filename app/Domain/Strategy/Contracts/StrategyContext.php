<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Contracts;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Exchange\Contracts\ExchangeTicker;

/**
 * 策略运行时上下文 DTO。
 *
 * 由 StrategyRunner 在每次决策前组装，传递给 Strategy.shouldOpen/shouldClose。
 * readonly + 不可变值对象，避免策略内修改污染调度器状态。
 */
readonly class StrategyContext
{
    /**
     * @param int                      $instanceId 策略实例 ID
     * @param array<string,mixed>      $params     已合并默认值的参数
     * @param ExchangePosition|null    $position   当前持仓（合约场景），现货为 null
     * @param array<ExchangeKline>     $recentBars 最近 N 条已闭 bar（按时间升序）
     * @param ExchangeTicker|null      $ticker     最新 tick
     */
    public function __construct(
        public int $instanceId,
        public array $params,
        public ?ExchangePosition $position,
        public ?ExchangeKline $positionKline,
        public array $recentBars,
        public ?ExchangeTicker $ticker,
    ) {
    }
}
