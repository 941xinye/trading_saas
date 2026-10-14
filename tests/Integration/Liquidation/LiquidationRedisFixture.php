<?php

declare(strict_types=1);

namespace Tests\Integration\Liquidation;

use App\Domain\Exchange\Contracts\LiquidationEvent;
use App\Domain\Liquidation\LiquidationClusterer;
use App\Domain\Liquidation\LiquidationMapService;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\ChecksRedisAvailability;
use Throwable;

/**
 * 强平聚类 Redis 集成测试的公共夹具（@group redis）。
 *
 * 隔离约定：
 *  - 使用专用合成 symbol（PHPUNITLIQ*），运行时注入桶宽，与生产 symbol 及
 *    poc:verify-liquidation 的 POCVERIFY* key 空间互不干扰；
 *  - setUp/tearDown 都 clearWindow 全量清理（ZSET/HASH/计数/eid 反向索引）；
 *  - tearDown 遵循 docs/pitfalls.md #24：$redisAvailable 标志 + try/catch 守卫，
 *    且清理发生在 parent::tearDown() 回滚 RefreshDatabase 事务之前。
 */
trait LiquidationRedisFixture
{
    use ChecksRedisAvailability;

    private const LIQ_EXCHANGE = 'binance';

    private const LIQ_SYMBOL_BTC = 'PHPUNITLIQBTCUSDT';

    private const LIQ_SYMBOL_ETH = 'PHPUNITLIQETHUSDT';

    private const LIQ_SIDES = ['BUY', 'SELL'];

    private const LIQ_WINDOWS = ['5m', '15m', '60m'];

    private bool $redisAvailable = false;

    private ?LiquidationClusterer $clusterer = null;

    private ?LiquidationMapService $mapService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        $this->redisAvailable = true;

        config([
            'liquidation.buckets.'.self::LIQ_SYMBOL_BTC => '60',
            'liquidation.buckets.'.self::LIQ_SYMBOL_ETH => '3',
        ]);

        $this->clusterer = new LiquidationClusterer;
        $this->mapService = new LiquidationMapService($this->clusterer);
        $this->cleanupFixtures();
    }

    protected function tearDown(): void
    {
        if ($this->redisAvailable) {
            try {
                $this->cleanupFixtures();
            } catch (Throwable) {
                // 清理失败不允许吞掉测试结果（key 自带 TTL，且 symbol 为测试专用隔离空间）
            }
        }

        parent::tearDown();
    }

    private function cleanupFixtures(): void
    {
        foreach ([self::LIQ_SYMBOL_BTC, self::LIQ_SYMBOL_ETH] as $symbol) {
            foreach (self::LIQ_SIDES as $side) {
                foreach (self::LIQ_WINDOWS as $window) {
                    $this->clusterer?->clearWindow(self::LIQ_EXCHANGE, $symbol, $side, $window);
                }
            }
        }
    }

    private function makeEvent(
        string $symbol,
        string $side,
        string $price,
        string $qty,
        ?int $tsMs = null,
    ): LiquidationEvent {
        return new LiquidationEvent(
            exchangeCode: self::LIQ_EXCHANGE,
            symbol: $symbol,
            side: $side,
            price: $price,
            qty: $qty,
            valueUsd: bcmul($price, $qty, 8),
            tsMs: $tsMs ?? (int) (microtime(true) * 1000),
        );
    }

    private function zsetKey(string $symbol, string $side, string $window): string
    {
        return $this->liqKey('z', $symbol, $side, $window);
    }

    private function bucketHashKey(string $symbol, string $side, string $window): string
    {
        return $this->liqKey('b', $symbol, $side, $window);
    }

    private function liqKey(string $kind, string $symbol, string $side, string $window): string
    {
        $prefix = (string) config('liquidation.redis_prefix', 'liq');

        return "{$prefix}:{$kind}:".self::LIQ_EXCHANGE.":{$symbol}:{$side}:{$window}";
    }

    private function zCard(string $symbol, string $side, string $window): int
    {
        return (int) Redis::connection('default')->zCard($this->zsetKey($symbol, $side, $window));
    }
}
