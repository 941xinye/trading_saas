<?php

declare(strict_types=1);

namespace Tests\Integration\Liquidation;

use App\Domain\Liquidation\LiquidationBucketPolicy;
use Tests\TestCase;

/**
 * 桶宽策略的 config 回退路径（bucketWidth / bucketFor 需要 Laravel 容器）。
 *
 * 无 Redis、无网络；RefreshDatabase 仅因继承 Tests\TestCase，default 组始终执行。
 */
class LiquidationBucketConfigTest extends TestCase
{
    private LiquidationBucketPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new LiquidationBucketPolicy;
    }

    public function test_configured_symbols_use_their_own_bucket_width(): void
    {
        static::assertSame('60', $this->policy->bucketWidth('BTCUSDT'));
        static::assertSame('3', $this->policy->bucketWidth('ETHUSDT'));
        static::assertSame('0.2', $this->policy->bucketWidth('SOLUSDT'));
    }

    public function test_unknown_symbol_falls_back_to_default_width(): void
    {
        static::assertSame('1', $this->policy->bucketWidth('NOTLISTEDUSDT'));
    }

    public function test_runtime_config_overrides_bucket_width_for_test_symbols(): void
    {
        // Redis 聚类测试用独立合成 symbol，运行时注入桶宽，避免依赖 POC 专用 config 项
        config(['liquidation.buckets.PHPUNITLIQBTCUSDT' => '60']);

        static::assertSame('60', $this->policy->bucketWidth('PHPUNITLIQBTCUSDT'));
    }

    public function test_bucket_for_returns_index_center_and_range(): void
    {
        $bucket = $this->policy->bucketFor('BTCUSDT', '60059');

        static::assertSame('1000', $bucket['index']);
        static::assertSame(0, bccomp($bucket['center'], '60000', 8));
        static::assertSame(0, bccomp($bucket['from'], '60000', 8));
        static::assertSame(0, bccomp($bucket['to'], '60060', 8));
    }
}
