<?php

declare(strict_types=1);

namespace App\Domain\Archive\Exceptions;

/**
 * 历史存储不可用（CH 连接拒绝/超时/HTTP 4xx/5xx 统一包装，R-009）。
 *
 * HistoricalQueryService 原样上抛不吞不改写；调用方据此返回明确降级错误（FR-008），
 * 「历史层暂不可用」与「真没数据」必须可区分。
 */
final class ArchiveStorageUnavailableException extends \RuntimeException
{
}
