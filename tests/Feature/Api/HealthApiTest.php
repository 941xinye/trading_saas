<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 生产探活端点（/api/v1/health）。
 *
 * 覆盖：公开可访问、组件结构、ClickHouse 降级、失败队列阈值。
 * ClickHouse 用 Http::fake 避免依赖真实归档库；Redis 不可用时端点仍返回 200（degraded）。
 */
class HealthApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'health.checks.clickhouse' => true,
            'health.failed_jobs_threshold' => 10,
            'archive.ch.host' => '127.0.0.1',
        ]);
    }

    public function test_health_endpoint_is_public_and_reports_all_components(): void
    {
        Http::fake(['*/ping' => Http::response('Ok.', 200)]);

        $response = $this->getJson('/api/v1/health');

        $response->assertOk()->assertJsonStructure([
            'status',
            'checked_at',
            'checks' => [
                'database' => ['status', 'latency_ms'],
                'redis' => ['status', 'latency_ms'],
                'clickhouse' => ['status', 'latency_ms'],
                'queue' => ['status', 'latency_ms', 'failed_last_24h'],
            ],
        ]);

        $this->assertSame('ok', $response->json('checks.database.status'));
        $this->assertSame('ok', $response->json('checks.clickhouse.status'));
        $this->assertSame('ok', $response->json('checks.queue.status'));
        $this->assertContains($response->json('status'), ['ok', 'degraded']);
    }

    public function test_clickhouse_failure_degrades_but_does_not_take_endpoint_down(): void
    {
        Http::fake(['*/ping' => Http::response('boom', 500)]);

        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertSame('failed', $response->json('checks.clickhouse.status'));
        $this->assertNotSame('down', $response->json('status'));
    }

    public function test_failed_jobs_over_threshold_degrades_queue_status(): void
    {
        Http::fake(['*/ping' => Http::response('Ok.', 200)]);

        foreach (range(1, 11) as $i) {
            DB::table('failed_jobs')->insert([
                'uuid' => 'health-test-'.$i,
                'connection' => 'redis',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'test',
                'failed_at' => now(),
            ]);
        }

        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertSame('degraded', $response->json('checks.queue.status'));
        $this->assertSame(11, $response->json('checks.queue.failed_last_24h'));
    }
}
