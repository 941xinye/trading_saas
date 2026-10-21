<?php

declare(strict_types=1);

namespace Tests\Integration\Risk;

use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Risk\Breaker\IsolatedLiquidationPriceCalculator;
use App\Domain\Risk\Contracts\PositionRiskDecision;
use App\Domain\Risk\Position\PositionRiskEventRepository;
use App\Domain\Risk\Position\PositionRiskMonitor;
use App\Events\PositionRiskAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\Support\RedisCleanup;
use Tests\TestCase;
use Throwable;

/**
 * 仓位风控监控器集成测试（spec 005 WS3）。
 *
 * 覆盖三档决策（warning/reduce/emergency_close）+ 单飞锁 + 15min 去重。
 * 需要真实 Redis（@group redis，不可用时 requireRedis 自动 skip）。
 *
 * BTCUSDT 10x 多头，entry=50000，MMR=0.0040：
 *   liqPrice = 45200
 *   safe      markPrice=50000  ratio=1.0   → null
 *   warning   markPrice=47600  ratio=0.50  → warning
 *   reduce    markPrice=46160  ratio=0.20  → reduce（减 50%）
 *   emergency markPrice=45680  ratio=0.10  → emergency_close（全平）
 */
#[Group('redis')]
class PositionRiskMonitorTest extends TestCase
{
    use ChecksRedisAvailability;
    use RedisCleanup;

    private const TENANT_ID = 990050;

    private const INSTANCE_ID = 990250;

    private const SYMBOL = 'BTCUSDT';

    private const ENTRY = '50000';

    private const LEVERAGE = 10;

    private const MMR = '0.0040';

    private const SIZE = '2';

    private bool $redisAvailable = false;

    private PositionRiskMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        $this->redisAvailable = true;

        Event::fake([PositionRiskAlert::class]);

        $this->scaffold();
        $this->clearRedis();

        $this->monitor = new PositionRiskMonitor(
            new IsolatedLiquidationPriceCalculator(),
            new PositionRiskEventRepository(),
        );
    }

    protected function tearDown(): void
    {
        if ($this->redisAvailable) {
            try {
                $this->clearRedis();
            } catch (Throwable) {
            }
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 决策路径
    // ------------------------------------------------------------------

    public function test_no_position_returns_null(): void
    {
        $decision = $this->monitor->evaluate(self::TENANT_ID, self::INSTANCE_ID, self::SYMBOL, null, '50000');

        static::assertNull($decision);
        Event::assertNotDispatched(PositionRiskAlert::class);
    }

    public function test_safe_position_returns_null(): void
    {
        // ratio = 1.0 > warning(0.50)
        $position = $this->makePosition(self::ENTRY, self::ENTRY);

        $decision = $this->monitor->evaluate(self::TENANT_ID, self::INSTANCE_ID, self::SYMBOL, $position, self::ENTRY);

        static::assertNull($decision);
        Event::assertNotDispatched(PositionRiskAlert::class);
    }

    public function test_warning_decision_dispatches_event_and_audits(): void
    {
        // markPrice=47600 → ratio=0.50 = warning threshold
        $position = $this->makePosition(self::ENTRY, '47600');

        $decision = $this->monitor->evaluate(self::TENANT_ID, self::INSTANCE_ID, self::SYMBOL, $position, '47600');

        static::assertInstanceOf(PositionRiskDecision::class, $decision);
        static::assertSame('warning', $decision->actionType);
        static::assertSame('margin_ratio_warning', $decision->reason);
        static::assertNull($decision->orderQty);
        static::assertNull($decision->reduceOnly);

        Event::assertDispatchedTimes(PositionRiskAlert::class, 1);

        $audit = DB::table('position_risk_events')
            ->where('strategy_instance_id', self::INSTANCE_ID)
            ->where('action_type', 'warning')
            ->first();

        static::assertNotNull($audit);
        static::assertSame('executed', $audit->status);
    }

    public function test_warning_dedup_skips_second_audit_and_event(): void
    {
        $position = $this->makePosition(self::ENTRY, '47600');

        // 第一次：写审计 + 发事件
        $this->monitor->evaluate(self::TENANT_ID, self::INSTANCE_ID, self::SYMBOL, $position, '47600');
        Event::assertDispatchedTimes(PositionRiskAlert::class, 1);
        static::assertSame(1, DB::table('position_risk_events')->count());

        // 第二次（15min 内）：去重，不写审计、不发事件
        $decision = $this->monitor->evaluate(self::TENANT_ID, self::INSTANCE_ID, self::SYMBOL, $position, '47600');

        static::assertSame('warning', $decision->actionType);
        Event::assertDispatchedTimes(PositionRiskAlert::class, 1); // 仍为 1
        static::assertSame(1, DB::table('position_risk_events')->count()); // 仍为 1
    }

    public function test_reduce_decision_has_reduce_only_and_half_qty(): void
    {
        // markPrice=46160 → ratio=0.20 = action threshold
        $position = $this->makePosition(self::ENTRY, '46160');

        $decision = $this->monitor->evaluate(self::TENANT_ID, self::INSTANCE_ID, self::SYMBOL, $position, '46160');

        static::assertInstanceOf(PositionRiskDecision::class, $decision);
        static::assertSame('reduce', $decision->actionType);
        static::assertSame('margin_ratio_action', $decision->reason);
        static::assertTrue($decision->reduceOnly);
        // reduce_ratio=0.50，size=2 → orderQty = bcmul('2','0.50',8) = '1.00000000'（bcmath scale 8，constitution II）
        static::assertSame('1.00000000', $decision->orderQty);

        Event::assertDispatched(PositionRiskAlert::class);

        $audit = DB::table('position_risk_events')
            ->where('strategy_instance_id', self::INSTANCE_ID)
            ->where('action_type', 'reduce')
            ->first();

        static::assertNotNull($audit);
        static::assertSame('pending', $audit->status);
    }

    public function test_emergency_close_decision_has_full_qty(): void
    {
        // markPrice=45680 → ratio=0.10 = emergency threshold
        $position = $this->makePosition(self::ENTRY, '45680');

        $decision = $this->monitor->evaluate(self::TENANT_ID, self::INSTANCE_ID, self::SYMBOL, $position, '45680');

        static::assertInstanceOf(PositionRiskDecision::class, $decision);
        static::assertSame('emergency_close', $decision->actionType);
        static::assertSame('margin_ratio_emergency', $decision->reason);
        static::assertTrue($decision->reduceOnly);
        // 全平 → orderQty = size = 2
        static::assertSame(self::SIZE, $decision->orderQty);

        Event::assertDispatched(PositionRiskAlert::class);
    }

    // ------------------------------------------------------------------
    // 单飞锁
    // ------------------------------------------------------------------

    public function test_lock_contention_returns_skipped(): void
    {
        // 预先占据单飞锁
        $lockKey = "tenant:" . self::TENANT_ID . ":position_risk:" . self::INSTANCE_ID . ":lock";
        Redis::connection('default')->set($lockKey, '1', 'EX', 30);

        $position = $this->makePosition(self::ENTRY, '46160'); // action 阈值

        $decision = $this->monitor->evaluate(self::TENANT_ID, self::INSTANCE_ID, self::SYMBOL, $position, '46160');

        static::assertInstanceOf(PositionRiskDecision::class, $decision);
        static::assertSame('skipped', $decision->actionType);
        static::assertSame('lock_contended', $decision->reason);
        static::assertNull($decision->orderQty);

        // 不应写审计
        static::assertSame(0, DB::table('position_risk_events')->count());
    }

    public function test_emergency_close_also_uses_lock(): void
    {
        // 紧急全平同样受单飞锁保护
        $lockKey = "tenant:" . self::TENANT_ID . ":position_risk:" . self::INSTANCE_ID . ":lock";
        Redis::connection('default')->set($lockKey, '1', 'EX', 30);

        $position = $this->makePosition(self::ENTRY, '45680');

        $decision = $this->monitor->evaluate(self::TENANT_ID, self::INSTANCE_ID, self::SYMBOL, $position, '45680');

        static::assertSame('skipped', $decision->actionType);
        static::assertSame('lock_contended', $decision->reason);
    }

    // ------------------------------------------------------------------
    // 事件 payload
    // ------------------------------------------------------------------

    public function test_alert_event_carries_correct_fields(): void
    {
        $position = $this->makePosition(self::ENTRY, '46160');

        $this->monitor->evaluate(self::TENANT_ID, self::INSTANCE_ID, self::SYMBOL, $position, '46160');

        Event::assertDispatched(PositionRiskAlert::class, function (PositionRiskAlert $event): bool {
            return $event->tenantId === self::TENANT_ID
                && $event->instanceId === self::INSTANCE_ID
                && $event->symbol === self::SYMBOL
                && $event->actionType === 'reduce'
                && $event->markPrice === '46160';
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makePosition(string $entryPrice, string $markPrice): ExchangePosition
    {
        return new ExchangePosition(
            symbol: self::SYMBOL,
            side: 'long',
            size: self::SIZE,
            entryPrice: $entryPrice,
            markPrice: $markPrice,
            liquidationPrice: '45200',
            leverage: self::LEVERAGE,
            unrealizedPnl: '0',
            margin: '1000',
        );
    }

    private function scaffold(): void
    {
        $now = now();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-prm-990050',
            'slug' => 'poc-prm-990050',
            'exchange_dek' => 'dummy-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance', 'name' => 'Binance',
            'base_url' => 'https://example.invalid', 'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot', 'futures']),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'futures_grid', 'name' => '合约网格', 'category' => 'futures',
            'risk_profile' => 'aggressive', 'default_params' => json_encode([]),
            'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('exchange_accounts')->insert([
            'id' => 990150,
            'tenant_id' => self::TENANT_ID,
            'exchange_id' => $exchangeId,
            'label' => 'poc-prm',
            'market' => 'futures',
            'encrypted_api_key' => 'poc',
            'encrypted_api_secret' => 'poc',
            'permissions' => json_encode(['futures']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('strategy_instances')->insert([
            'id' => self::INSTANCE_ID,
            'tenant_id' => self::TENANT_ID,
            'strategy_id' => $strategyId,
            'exchange_account_id' => 990150,
            'symbol' => self::SYMBOL,
            'interval' => '5m',
            'params' => json_encode([]),
            'allocated_capital' => '1000',
            'status' => 'running',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function clearRedis(): void
    {
        $prefix = 'tenant:'.self::TENANT_ID.':position_risk:'.self::INSTANCE_ID;
        $this->cleanupRedis([$prefix.':lock', $prefix.':warned']);
    }
}
