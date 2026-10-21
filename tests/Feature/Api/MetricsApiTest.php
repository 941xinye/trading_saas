<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Observability\MetricsStore;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 指标端点（/api/v1/metrics）。
 *
 * 覆盖：公开可访问、Prometheus 文本格式、计数器/仪表盘输出。
 */
class MetricsApiTest extends TestCase
{
    protected function tearDown(): void
    {
        try {
            $connection = Redis::connection();
            $prefix = (string) $connection->getOption(\Redis::OPT_PREFIX);
            foreach ($connection->keys('metrics:*') as $key) {
                $unprefixed = $prefix !== '' ? str_replace($prefix, '', $key) : $key;
                $connection->del($unprefixed);
            }
        } catch (\Throwable) {
            // 忽略
        }
        parent::tearDown();
    }
    public function test_metrics_endpoint_is_public_and_returns_prometheus_format(): void
    {
        $response = $this->get('/api/v1/metrics');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        $body = $response->content();
        self::assertStringContainsString('# HELP trading_orders_total', $body);
        self::assertStringContainsString('# TYPE trading_orders_total counter', $body);
    }

    public function test_counter_increment_is_exposed(): void
    {
        $store = app(MetricsStore::class);
        $store->increment('trading_orders_total', [
            'tenant_id' => 1001,
            'side' => 'buy',
            'status' => 'filled',
        ], 3);

        $response = $this->get('/api/v1/metrics');
        $body = $response->content();

        self::assertStringContainsString(
            'trading_orders_total{tenant_id="1001",side="buy",status="filled"} 3',
            $body,
        );
    }

    public function test_gauge_is_exposed(): void
    {
        $store = app(MetricsStore::class);
        $store->gauge('trading_queue_backlog', ['queue' => 'orders'], 42);

        $response = $this->get('/api/v1/metrics');
        $body = $response->content();

        self::assertStringContainsString('# TYPE trading_queue_backlog gauge', $body);
        self::assertStringContainsString(
            'trading_queue_backlog{queue="orders"} 42',
            $body,
        );
    }
}
