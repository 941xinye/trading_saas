<?php

declare(strict_types=1);

namespace App\Domain\Archive\Exceptions;

/**
 * 统一历史查询上限/游标违规（FR-011 / contracts/historical-query-contract.md §2）。
 *
 * pageSize 超 archive.query_max_rows、游标非法（篡改/过期格式）时抛出——
 * 显式报错，不静默截断、不退化为全表扫描。
 */
final class ArchiveQueryLimitException extends \RuntimeException
{
}
