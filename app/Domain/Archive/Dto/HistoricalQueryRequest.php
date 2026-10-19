<?php

declare(strict_types=1);

namespace App\Domain\Archive\Dto;

use App\Domain\Archive\Contracts\ArchiveDataType;

/**
 * 统一历史查询请求（不可变 DTO，data-model.md §4 / contracts/historical-query-contract.md §3）。
 *
 * [from, to) 左闭右开全 UTC。**无 tenantId 字段**（R-008 物理隔离：tenant 类型由
 * TenantContext 注入，"指定他人租户"的表达能力不存在）。
 *
 * 过滤开放表（§3，构造即校验，错用抛 InvalidArgumentException）：
 *   symbol 全类型 ✓；exchangeCode 仅 KlineBar/LiquidationEvent；interval 仅 KlineBar。
 */
final readonly class HistoricalQueryRequest
{
    public function __construct(
        public ArchiveDataType $dataType,
        public \DateTimeImmutable $fromUtc,
        public \DateTimeImmutable $toUtc,
        public ?string $symbol = null,
        public ?string $exchangeCode = null,
        public ?string $interval = null,
        /** null = 由服务层取 config archive.query_page_size（默认 1000） */
        public ?int $pageSize = null,
        /** 上页返回的不透明游标（(时间列, id) base64） */
        public ?string $cursor = null,
    ) {
        if ($this->fromUtc >= $this->toUtc) {
            throw new \InvalidArgumentException('from 必须早于 to（[from, to) 左闭右开）');
        }
        if ($this->pageSize !== null && $this->pageSize < 1) {
            throw new \InvalidArgumentException('pageSize 必须 >= 1');
        }
        if ($this->exchangeCode !== null
            && ! in_array($this->dataType, [ArchiveDataType::KlineBar, ArchiveDataType::LiquidationEvent], true)) {
            throw new \InvalidArgumentException(sprintf('exchangeCode 过滤仅 KlineBar/LiquidationEvent 开放（%s 不支持）', $this->dataType->value));
        }
        if ($this->interval !== null && $this->dataType !== ArchiveDataType::KlineBar) {
            throw new \InvalidArgumentException(sprintf('interval 过滤仅 KlineBar 开放（%s 不支持）', $this->dataType->value));
        }
    }
}
