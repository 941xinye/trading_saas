<?php

declare(strict_types=1);

namespace Tests\Integration\Signal;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Signal\BarAggregator;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\Support\RedisCleanup;
use Tests\TestCase;
use Throwable;

/**
 * BarAggregator 1m→5m 聚合 + 崩溃恢复测试（P5.5 步骤 3，迁自 poc:verify-ai [1/7] 共 17 项断言）。
 *
 * 隔离约定：合成 symbol=PHPUNITAGG，Redis key 与真实行情流永不冲突；
 * Redis 不可用时 @group redis 用例自动 skip，1m 直通用例属 default 组无需 Redis。
 */
class BarAggregatorTest extends TestCase
{
    use ChecksRedisAvailability;
    use RedisCleanup;

    private const SYMBOL = 'PHPUNITAGG';

    private const STATE_KEY = 'market:agg:binance:'.self::SYMBOL.':5m';

    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->redisAvailable) {
            try {
                $this->cleanupRedis([self::STATE_KEY]);
            } catch (Throwable) {
                // 清理失败不允许污染测试结果（坑 #24：外部清理必须守卫）
            }
        }

        parent::tearDown();
    }

    /**
     * poc 断言 [1]-1：interval=1m 直通返回原 bar（不读不写 Redis）。
     */
    public function test_one_minute_interval_passes_through_original_bar(): void
    {
        $agg = new BarAggregator;
        $bar = $this->synthetic1mBar($this->alignedBucketBase(), 0);

        static::assertSame($bar, $agg->aggregate($bar, '1m'));
    }

    /**
     * poc 断言 [1]-2~12：同桶 5 根合并，第 6 根跨桶产出闭合 5m bar，OHLCV/trades/ticks 正确。
     */
    #[Group('redis')]
    public function test_full_five_minute_bucket_aggregates_ohlcv_and_ticks(): void
    {
        $this->requireRedis();
        $this->redisAvailable = true;
        $this->cleanupRedis([self::STATE_KEY]);

        $base = $this->alignedBucketBase();
        $agg = new BarAggregator;

        $closed = null;
        for ($i = 0; $i <= 5; $i++) {
            $closed = $agg->aggregate($this->synthetic1mBar($base + $i * 60_000, $i), '5m') ?? $closed;
        }

        static::assertNotNull($closed);
        static::assertSame($base, $closed->openTime);
        static::assertSame($base + 299_999, $closed->closeTime);
        static::assertSame('100', $closed->open);
        static::assertSame('118', $closed->high);
        static::assertSame('86', $closed->low);
        static::assertSame('104', $closed->close);
        static::assertSame(0, bccomp($closed->volume, '7.5', 8));
        static::assertSame(0, bccomp($closed->quoteVolume, '7500', 8));
        static::assertSame(15, $closed->tradesCount);
        static::assertSame(5, $agg->lastClosedTicks('binance', self::SYMBOL, '5m'));
    }

    /**
     * poc 断言 [1]-13~17：新实例从 Redis hydrate 未闭桶，同桶返回 null、跨桶闭合残次桶 ticks=2。
     */
    #[Group('redis')]
    public function test_crash_recovery_hydrates_partial_bucket_from_redis(): void
    {
        $this->requireRedis();
        $this->redisAvailable = true;
        $this->cleanupRedis([self::STATE_KEY]);

        $base = $this->alignedBucketBase();
        $agg = new BarAggregator;

        // 先喂满一个完整桶并跨入下一桶（i=5 落在 base+300000 新桶，留下 ticks=1 的未闭桶）
        for ($i = 0; $i <= 5; $i++) {
            $agg->aggregate($this->synthetic1mBar($base + $i * 60_000, $i), '5m');
        }

        // 模拟崩溃：全新实例 hydrate Redis 中的未闭桶
        $agg2 = new BarAggregator;
        $sameBucket = $agg2->aggregate($this->synthetic1mBar($base + 360_000, 6), '5m');
        static::assertNull($sameBucket);

        $closed2 = $agg2->aggregate($this->synthetic1mBar($base + 600_000, 7), '5m');
        static::assertNotNull($closed2);
        static::assertSame('100', $closed2->open);
        static::assertSame('106', $closed2->close);
        static::assertSame(6, $closed2->tradesCount);
        static::assertSame(0, bccomp($closed2->volume, '3.0', 8));
        static::assertSame(2, $agg2->lastClosedTicks('binance', self::SYMBOL, '5m'));
    }

    private function alignedBucketBase(): int
    {
        return intdiv(time() * 1000, 300_000) * 300_000;
    }

    private function synthetic1mBar(int $openTime, int $i): ExchangeKline
    {
        return new ExchangeKline(
            exchangeCode: 'binance',
            symbol: self::SYMBOL,
            interval: '1m',
            openTime: $openTime,
            closeTime: $openTime + 59_999,
            open: '100',
            high: (string) (110 + 2 * $i),
            low: (string) (90 - $i),
            close: (string) (100 + $i),
            volume: '1.5',
            quoteVolume: '1500',
            tradesCount: 3,
        );
    }
}
