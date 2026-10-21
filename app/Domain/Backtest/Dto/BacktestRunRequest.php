<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Dto;

/**
 * 回测发起参数（不可变值对象）。
 *
 * 构造即校验（data-model.md「验证规则」节）：
 *  - fromUtc < toUtc，symbol 大写规范化，interval ∈ {1m,5m,15m,1h}
 *  - 策略白名单在 BacktestRunService::validateStrategy（PlanService 双闸门）执行
 *  - 撮合假设校验在 MatchingAssumptions 构造器内（contract 层唯一入口）
 */
final readonly class BacktestRunRequest
{
    /** 回测可用 K 线周期（data-model.md：与实例周期一致） */
    public const INTERVALS = ['1m', '5m', '15m', '1h'];

    public string $symbol;

    public string $interval;

    public function __construct(
        public int $tenantId,
        /** 四策略白名单之一（spot_grid/spot_dca/futures_grid/custom_dsl） */
        public string $strategyCode,
        /** 实例参数或 inline DSL 参数（含 dsl_script 全文） */
        public array $params,
        string $symbol,
        string $interval,
        public \DateTimeImmutable $fromUtc,
        public \DateTimeImmutable $toUtc,
        public MatchingAssumptions $assumptions,
        /** null = config archive.query_page_size */
        public ?int $pageSize = null,
        /** null = 新建任务（dispatch 模式）；有值 = 既有 run（sync 直跑） */
        public ?int $runId = null,
    ) {
        if ($this->tenantId <= 0) {
            throw new \InvalidArgumentException('tenant_id 必须 > 0');
        }

        $this->symbol = strtoupper(trim($symbol));
        if ($this->symbol === '') {
            throw new \InvalidArgumentException('symbol 不能为空');
        }

        $this->interval = strtolower(trim($interval));
        if (! in_array($this->interval, self::INTERVALS, true)) {
            throw new \InvalidArgumentException(
                sprintf('interval 须为 %s 之一', implode('/', self::INTERVALS))
            );
        }

        if ($this->fromUtc >= $this->toUtc) {
            throw new \InvalidArgumentException('回测区间必须满足 from_utc < to_utc');
        }

        if ($this->pageSize !== null && $this->pageSize <= 0) {
            throw new \InvalidArgumentException('page_size 必须 > 0');
        }
    }
}
