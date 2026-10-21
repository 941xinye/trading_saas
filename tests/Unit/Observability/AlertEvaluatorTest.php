<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use App\Domain\Observability\AlertEvaluator;
use App\Domain\Observability\MetricsStore;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 告警规则评估器：rate / max / sum 三种规则类型的命中判定。
 */
final class AlertEvaluatorTest extends TestCase
{
    private MetricsStore $store;

    private AlertEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = app(MetricsStore::class);
        $this->evaluator = new AlertEvaluator($this->store);
    }

    protected function tearDown(): void
    {
        // 清理本次测试写入的指标（phpredis 全局前缀下 keys() 返回全名，del 需去掉前缀）
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

    public function test_rate_rule_fires_when_failure_rate_exceeds_threshold(): void
    {
        // 20 单中 5 单 failed → 25% > 10%
        $this->store->increment('trading_orders_total', ['tenant_id' => 1, 'side' => 'buy', 'status' => 'filled'], 15);
        $this->store->increment('trading_orders_total', ['tenant_id' => 1, 'side' => 'buy', 'status' => 'failed'], 5);

        $fired = $this->evaluator->evaluate();

        $rules = array_column($fired, 'rule');
        self::assertContains('order_failure_rate_high', $rules);
    }

    public function test_rate_rule_below_min_total_does_not_fire(): void
    {
        // 总数 5 < min_total 10
        $this->store->increment('trading_orders_total', ['tenant_id' => 1, 'side' => 'buy', 'status' => 'filled'], 3);
        $this->store->increment('trading_orders_total', ['tenant_id' => 1, 'side' => 'buy', 'status' => 'failed'], 2);

        $fired = $this->evaluator->evaluate();

        $rules = array_column($fired, 'rule');
        self::assertNotContains('order_failure_rate_high', $rules);
    }

    public function test_max_rule_fires_when_queue_backlog_exceeds_threshold(): void
    {
        $this->store->gauge('trading_queue_backlog', ['queue' => 'orders'], 1500);
        $this->store->gauge('trading_queue_backlog', ['queue' => 'ai'], 10);

        $fired = $this->evaluator->evaluate();

        $rules = array_column($fired, 'rule');
        self::assertContains('queue_backlog_high', $rules);
    }

    public function test_sum_rule_fires_when_breakers_open(): void
    {
        $this->store->gauge('trading_breaker_open', ['tenant_id' => 1, 'instance_id' => 10], 1);

        $fired = $this->evaluator->evaluate();

        $rules = array_column($fired, 'rule');
        self::assertContains('breaker_open', $rules);
    }

    public function test_no_alerts_when_all_metrics_below_threshold(): void
    {
        $this->store->increment('trading_orders_total', ['tenant_id' => 1, 'side' => 'buy', 'status' => 'filled'], 50);
        $this->store->gauge('trading_queue_backlog', ['queue' => 'orders'], 5);

        $fired = $this->evaluator->evaluate();

        self::assertSame([], $fired);
    }
}
