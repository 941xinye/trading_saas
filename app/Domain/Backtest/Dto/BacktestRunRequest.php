<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Dto;

/**
 * 回测发起参数（不可变值对象）。
 */
final readonly class BacktestRunRequest
{
    public function __construct(
        public int $tenantId,
        /** 四策略白名单之一（spot_grid/spot_dca/futures_grid/custom_dsl） */
        public string $strategyCode,
        /** 实例参数或 inline DSL 参数（含 dsl_script 全文） */
        public array $params,
        public string $symbol,
        /** 1m/5m/15m/1h（与实例周期一致） */
        public string $interval,
        public \DateTimeImmutable $fromUtc,
        public \DateTimeImmutable $toUtc,
        public MatchingAssumptions $assumptions,
        /** null = config archive.query_page_size */
        public ?int $pageSize = null,
        /** null = 新建任务（dispatch 模式）；有值 = 既有 run（sync 直跑） */
        public ?int $runId = null,
    ) {
    }
}
