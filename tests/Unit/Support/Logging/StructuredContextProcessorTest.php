<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Logging;

use App\Domain\Tenancy\TenantContext;
use App\Support\Logging\StructuredContextProcessor;
use Illuminate\Support\Facades\Context;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * 结构化日志处理器：为每条日志注入 env / tenant_id / request_id。
 */
final class StructuredContextProcessorTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::flush();
        parent::tearDown();
    }

    public function test_injects_env_and_request_id(): void
    {
        $processor = new StructuredContextProcessor();
        $record = $this->makeRecord();

        $result = $processor($record);

        self::assertSame(app()->environment(), $result->extra['env']);
        self::assertNotEmpty($result->extra['request_id']);
    }

    public function test_request_id_stable_within_same_context(): void
    {
        $processor = new StructuredContextProcessor();

        $first = $processor($this->makeRecord());
        $second = $processor($this->makeRecord());

        self::assertSame($first->extra['request_id'], $second->extra['request_id']);
    }

    public function test_injects_tenant_id_when_context_set(): void
    {
        TenantContext::initialize(99123);

        $processor = new StructuredContextProcessor();
        $result = $processor($this->makeRecord());

        self::assertSame(99123, $result->extra['tenant_id']);
    }

    public function test_tenant_id_absent_for_platform_admin(): void
    {
        $processor = new StructuredContextProcessor();
        $result = $processor($this->makeRecord());

        self::assertArrayNotHasKey('tenant_id', $result->extra);
    }

    private function makeRecord(): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'hello',
            context: [],
            extra: [],
        );
    }
}
