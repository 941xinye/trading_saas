<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Context;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * 为每条日志注入统一上下文字段，方便日志聚合与检索。
 *
 * 注入字段：
 *  - env:        APP_ENV
 *  - tenant_id:  当前租户（平台超管为 null）
 *  - request_id: 请求/Job 级唯一 ID，串联同一次调用的多条日志
 *
 * request_id 基于 Laravel Context 惰性生成：首次调用时写入，
 * Octane 在请求/Job 结束时自动重置 Context，不会跨请求泄漏。
 */
final class StructuredContextProcessor implements ProcessorInterface
{
    private const CTXT_KEY_REQUEST_ID = 'logging.request_id';

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        $extra['env'] = (string) (app()->environment() ?? 'production');

        $tenantId = TenantContext::getTenantId();
        if ($tenantId !== null) {
            $extra['tenant_id'] = $tenantId;
        }

        $requestId = Context::get(self::CTXT_KEY_REQUEST_ID);
        if (! is_string($requestId) || $requestId === '') {
            $requestId = bin2hex(random_bytes(8));
            Context::add(self::CTXT_KEY_REQUEST_ID, $requestId);
        }
        $extra['request_id'] = $requestId;

        return $record->with(extra: $extra);
    }
}
