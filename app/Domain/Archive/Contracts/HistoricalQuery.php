<?php

declare(strict_types=1);

namespace App\Domain\Archive\Contracts;

use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Archive\Dto\HistoricalQueryResult;

/**
 * 统一历史查询契约（spec 004 US2 / contracts/historical-query-contract.md §1）。
 *
 * 按数据类型 + UTC 时间范围 [from, to) + 可选过滤查历史，以 applied_watermark 为基准
 * 自动路由冷热两层并归并为完整有序结果，调用方不感知数据所在层（R-007）。
 *
 * 边界：仅平台内部服务层契约，不暴露 HTTP API。
 */
interface HistoricalQuery
{
    /**
     * @throws \App\Domain\Archive\Exceptions\ArchiveStorageUnavailableException 历史层不可用（原样上抛，不吞不改写）
     * @throws \App\Domain\Archive\Exceptions\ArchiveQueryLimitException         pageSize 超上限 / 游标非法
     * @throws \InvalidArgumentException                                         参数非法（类型不支持的过滤字段、from >= to 等）
     */
    public function query(HistoricalQueryRequest $request): HistoricalQueryResult;
}
