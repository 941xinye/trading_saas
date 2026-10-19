<?php

declare(strict_types=1);

namespace App\Domain\Archive\Dto;

/**
 * 统一历史查询结果（不可变 DTO，data-model.md §4）。
 *
 * rows：全结果 (时间列 ASC, id ASC) 有序；行 = 列名 => 字符串/null 字典
 * （Decimal/时间/整数全字符串化，R-004，消费端 bcmath 直用；投影列 = ArchiveType::canonicalColumns()，
 * 冷热两侧同形，不含 batch_id/archived_at 审计列）。
 */
final readonly class HistoricalQueryResult
{
    /** servedFrom 观测取值（仅测试/运维观测，不参与业务逻辑） */
    public const HOT = 'HOT';

    public const COLD = 'COLD';

    public const MERGED = 'MERGED';

    /**
     * @param list<array<string, string|null>> $rows
     * @param ?string                          $nextPageCursor null = 无更多页
     * @param self::HOT|self::COLD|self::MERGED $servedFrom
     */
    public function __construct(
        public array $rows,
        public ?string $nextPageCursor,
        public string $servedFrom,
    ) {
    }
}
