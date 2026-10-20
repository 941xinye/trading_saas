<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * DSL 回测预览契约（spec 006 WS6 T028 / contracts §7 / FR-012）。
 *
 * 复用 P8 HistoricalQuery(KlineBar) 分页回放单条 DSL，与实跑共用同一
 * DslInterpreter + IndicatorCalculator（SC-006）。
 */
interface DslPreviewService
{
    /**
     * @throws DslValidationException 脚本非法（编译期即拒，不进入回放）
     */
    public function preview(
        int $tenantId,
        string $script,
        string $symbol,
        string $interval,
        \DateTimeImmutable $fromUtc,
        \DateTimeImmutable $toUtc,
        ?int $pageSize = null,
    ): DslPreviewResult;
}
