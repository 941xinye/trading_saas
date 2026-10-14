<?php

declare(strict_types=1);

namespace Tests\Unit\Liquidation;

use App\Domain\Liquidation\LiquidationBucketPolicy;
use PHPUnit\Framework\TestCase;

/**
 * 强平聚类桶宽策略的纯计算逻辑（迁移自 poc:verify-liquidation 断言 #3）。
 *
 * bucketIndex / bucketCenter / bucketRange 均以显式桶宽入参，不读 config、
 * 不启动 Laravel；按 symbol 取桶宽（bucketWidth）的配置回退路径见 Integration 测试。
 */
class LiquidationBucketPolicyTest extends TestCase
{
    private LiquidationBucketPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new LiquidationBucketPolicy;
    }

    public function test_bucket_index_keeps_0_1_percent_boundary_in_same_bucket(): void
    {
        // 旧断言 #3：bw=60（BTC@60000 的 0.1%）边界，60000/60059 同桶、60060 异桶
        $i1 = $this->policy->bucketIndex('60000', '60'); // 1000
        $i2 = $this->policy->bucketIndex('60059', '60'); // 1000.98… → 截断 1000
        $i3 = $this->policy->bucketIndex('60060', '60'); // 1001

        static::assertSame('1000', $i1);
        static::assertSame($i1, $i2);
        static::assertNotSame($i1, $i3);
        static::assertSame('1001', $i3);
    }

    public function test_bucket_center_is_index_times_width(): void
    {
        static::assertSame('60000.00000000', $this->policy->bucketCenter('1000', '60'));
        static::assertSame('3000.00000000', $this->policy->bucketCenter('1000', '3'));
    }

    public function test_bucket_range_is_left_closed_right_open(): void
    {
        [$from, $to] = $this->policy->bucketRange('1000', '60');

        static::assertSame(0, bccomp($from, '60000', 8));
        static::assertSame(0, bccomp($to, '60060', 8));
        // 边界价归属：from 在桶内、to 落入下一桶
        static::assertSame('1000', $this->policy->bucketIndex('60000', '60'));
        static::assertSame('1001', $this->policy->bucketIndex('60060', '60'));
    }

    public function test_non_positive_bucket_width_returns_index_zero(): void
    {
        static::assertSame('0', $this->policy->bucketIndex('60000', '0'));
    }

    public function test_fractional_width_uses_bcmath_truncation(): void
    {
        // SOL 桶宽 0.2：200.9 应截断到桶 1004（200.9/0.2=1004.5），不受浮点取整影响
        static::assertSame('1004', $this->policy->bucketIndex('200.9', '0.2'));
        static::assertSame('1005', $this->policy->bucketIndex('201.0', '0.2'));
    }
}
