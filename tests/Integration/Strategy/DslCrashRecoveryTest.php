<?php

declare(strict_types=1);

namespace Tests\Integration\Strategy;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyState;
use App\Domain\Strategy\Custom\DslStrategy;
use App\Domain\Strategy\Recovery\PendingOrderRecovery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\Fakes\FakeExchangeAdapter;
use Tests\TestCase;

/**
 * DSL 崩溃恢复测试（tasks T014 / FR-010 / SC-005，constitution IV）。
 *
 * 状态落 Redis → 新进程 restoreState → PendingOrderRecovery 核实：
 *  filled 只入账一次（重放幂等）；submitted/网络异常保守阻塞、不重复下单；
 *  查无 → 释放占位后可重新触发。
 *
 * @group redis：Redis 关闭时 requireRedis() 自动 skip。
 */
#[Group('redis')]
class DslCrashRecoveryTest extends TestCase
{
    use ChecksRedisAvailability;

    private const TENANT_ID = 990090;

    private const INSTANCE_ID = 990290;

    private PendingOrderRecovery $recovery;

    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireRedis();
        $this->redisAvailable = true;
        $this->recovery = app(PendingOrderRecovery::class);
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        // 清理先于 parent::tearDown() 回滚 RefreshDatabase 事务（docs/pitfalls #24）
        if ($this->redisAvailable) {
            Redis::connection('default')->del($this->stateKey());
        }

        parent::tearDown();
    }

    public function test_filled_pending_order_is_booked_once_and_replay_is_idempotent(): void
    {
        $cid = '990090_990290_crash_a';
        [$strategy, $openQty] = $this->strategyWithPendingOrder($cid);

        $adapter = new FakeExchangeAdapter;
        $adapter->ordersByCid[$cid] = $this->order($cid, 'filled', $openQty, '110');

        // 崩溃前：状态已写入 Redis
        Redis::connection('default')->set($this->stateKey(), json_encode($strategy->getState()->toArray()));

        // 新进程：构造同参策略 → 从 Redis 恢复
        $restored = $this->strategy();
        $this->restoreFromRedis($restored);

        static::assertSame([$cid], $restored->pendingClientOrderIds(), 'pending 单作为崩溃恢复锚点被还原');

        // 核实前：在途单未决 → 新 bar 不产出重复信号（单飞）
        $restored->onBar($this->bar(1_760_000_100_000, '120'));
        static::assertNull($restored->shouldOpen($this->ctx()), '在途单未核实前不重复下单');

        $stats = $this->recovery->recover($restored, $adapter, 'BTCUSDT', self::TENANT_ID, self::INSTANCE_ID);
        static::assertSame(['filled' => 1, 'released' => 0, 'submitted' => 0, 'unknown' => 0], $stats);
        static::assertSame([], $restored->pendingClientOrderIds());

        $snap = $restored->getState()->positionSnapshot;
        static::assertSame($openQty, $snap['position']['qty'], '成交入账一次');
        static::assertSame('110', $snap['position']['entry_price']);
        static::assertTrue($snap['dsl']['recovered'], '一次性恢复标志置位');

        // 重放：pending 已空 → 不重复核实、不重复入账
        $replay = $this->recovery->recover($restored, $adapter, 'BTCUSDT', self::TENANT_ID, self::INSTANCE_ID);
        static::assertSame(['filled' => 0, 'released' => 0, 'submitted' => 0, 'unknown' => 0], $replay);
        static::assertSame(
            $openQty,
            $restored->getState()->positionSnapshot['position']['qty'],
            '重放不重复入账（位置不翻倍）',
        );
        static::assertSame(1, (int) DB::table('strategy_orders')->where('client_order_id', $cid)->count());
        static::assertSame('filled', DB::table('strategy_orders')->where('client_order_id', $cid)->value('status'));
    }

    public function test_submitted_pending_order_stays_blocked_and_prevents_new_entry(): void
    {
        $cid = '990090_990290_crash_b';
        $strategy = $this->strategyWithPendingOrder($cid)[0];

        Redis::connection('default')->set($this->stateKey(), json_encode($strategy->getState()->toArray()));

        $restored = $this->strategy();
        $this->restoreFromRedis($restored);

        $adapter = new FakeExchangeAdapter;
        $adapter->ordersByCid[$cid] = $this->order($cid, 'submitted', '0', null);

        $stats = $this->recovery->recover($restored, $adapter, 'BTCUSDT', self::TENANT_ID, self::INSTANCE_ID);
        static::assertSame(['filled' => 0, 'released' => 0, 'submitted' => 1, 'unknown' => 0], $stats);
        static::assertSame([$cid], $restored->pendingClientOrderIds(), '订单仍有效 → 继续挂');

        // 仍未决：新 bar 不得开仓/平仓 → 无重复下单
        $restored->onBar($this->bar(1_760_000_100_000, '120'));
        static::assertNull($restored->shouldOpen($this->ctx()));
        static::assertNull($restored->shouldClose($this->ctx()));
        static::assertSame('pending', DB::table('strategy_orders')->where('client_order_id', $cid)->value('status'));
    }

    public function test_network_error_keeps_pending_blocked_conservatively(): void
    {
        $cid = '990090_990290_crash_c';
        $strategy = $this->strategyWithPendingOrder($cid)[0];

        Redis::connection('default')->set($this->stateKey(), json_encode($strategy->getState()->toArray()));

        $restored = $this->strategy();
        $this->restoreFromRedis($restored);

        $adapter = new FakeExchangeAdapter;
        $adapter->throwOnGetOrder = true; // 查询异常

        $stats = $this->recovery->recover($restored, $adapter, 'BTCUSDT', self::TENANT_ID, self::INSTANCE_ID);
        static::assertSame(['filled' => 0, 'released' => 0, 'submitted' => 0, 'unknown' => 1], $stats);
        static::assertSame([$cid], $restored->pendingClientOrderIds(), '网络异常保守保持阻塞');

        // 绝不允许在核实失败后重复下单
        $restored->onBar($this->bar(1_760_000_100_000, '120'));
        static::assertNull($restored->shouldOpen($this->ctx()));
    }

    public function test_order_not_found_releases_placeholder_and_allows_retrigger(): void
    {
        $cid = '990090_990290_crash_d';
        $strategy = $this->strategyWithPendingOrder($cid)[0];

        Redis::connection('default')->set($this->stateKey(), json_encode($strategy->getState()->toArray()));

        $restored = $this->strategy();
        $this->restoreFromRedis($restored);

        // ordersByCid 不注入 → 查无此单 → 释放占位
        $adapter = new FakeExchangeAdapter;

        $stats = $this->recovery->recover($restored, $adapter, 'BTCUSDT', self::TENANT_ID, self::INSTANCE_ID);
        static::assertSame(['filled' => 0, 'released' => 1, 'submitted' => 0, 'unknown' => 0], $stats);
        static::assertSame([], $restored->pendingClientOrderIds());
        static::assertSame('failed', DB::table('strategy_orders')->where('client_order_id', $cid)->value('status'));

        // 占位释放后：条件仍成立可重新开仓（非重复下单，而是新意图）
        $restored->onBar($this->bar(1_760_000_100_000, '120'));
        static::assertNotNull($restored->shouldOpen($this->ctx()));
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    /**
     * 构造一个「onBar 产信号 → 绑定 cid」的崩溃前策略，并落库 pending 订单行。
     *
     * @return array{0:DslStrategy,1:string}
     */
    private function strategyWithPendingOrder(string $cid): array
    {
        $strategy = $this->strategy();
        $strategy->onBar($this->bar(1_760_000_040_000, '110'));

        $open = $strategy->shouldOpen($this->ctx());
        static::assertNotNull($open, '前置：崩溃前已产出开仓信号');
        $qty = (string) $open['qty'];
        $strategy->attachClientOrderId($cid, (string) $open['side'], $qty);

        $now = now();
        DB::table('strategy_orders')->insert([
            'tenant_id' => self::TENANT_ID,
            'strategy_instance_id' => self::INSTANCE_ID,
            'exchange_order_id' => null,
            'client_order_id' => $cid,
            'symbol' => 'BTCUSDT', 'side' => 'buy', 'type' => 'market', 'quantity' => $qty,
            'status' => 'pending', 'filled_qty' => '0', 'fee' => '0',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return [$strategy, $qty];
    }

    private function restoreFromRedis(DslStrategy $strategy): void
    {
        $raw = Redis::connection('default')->get($this->stateKey());
        static::assertIsString($raw, 'Redis 中存在崩溃前状态');

        $decoded = json_decode((string) $raw, true);
        static::assertIsArray($decoded);

        $strategy->restoreState(StrategyState::fromArray($decoded));
    }

    private function stateKey(): string
    {
        return sprintf('tenant:%d:strategy:%d:state', self::TENANT_ID, self::INSTANCE_ID);
    }

    private function strategy(): DslStrategy
    {
        $params = [
            'dsl_script' => $this->script(),
            'symbol' => 'BTCUSDT',
            'tenant_id' => self::TENANT_ID,
            'allocated_capital' => '1000',
        ];

        return new DslStrategy(self::INSTANCE_ID, $params, new StrategyContext(self::INSTANCE_ID, $params, null, null, [], null));
    }

    private function ctx(): StrategyContext
    {
        return new StrategyContext(self::INSTANCE_ID, [], null, null, [], null);
    }

    private function bar(int $openTimeMs, string $close): ExchangeKline
    {
        return new ExchangeKline(
            exchangeCode: 'binance',
            symbol: 'BTCUSDT',
            interval: '1m',
            openTime: $openTimeMs,
            closeTime: $openTimeMs + 59_999,
            open: $close,
            high: $close,
            low: $close,
            close: $close,
            volume: '1',
            quoteVolume: '1',
            tradesCount: 1,
        );
    }

    private function order(string $cid, string $status, string $filledQty, ?string $avgPrice): ExchangeOrder
    {
        return new ExchangeOrder(
            exchangeOrderId: 'ex-'.$cid,
            clientOrderId: $cid,
            symbol: 'BTCUSDT',
            side: 'buy',
            type: 'market',
            quantity: $filledQty,
            price: null,
            stopPrice: null,
            leverage: null,
            reduceOnly: false,
            status: $status,
            filledQty: $filledQty,
            avgFillPrice: $avgPrice,
            fee: '0',
            placedAt: 1_760_000_000,
            filledAt: $status === 'filled' ? 1_760_000_001 : null,
        );
    }

    private function script(): string
    {
        return implode("\n", [
            'version: 1',
            'name: crash-recovery',
            'symbol: BTCUSDT',
            'interval: 1m',
            'direction: long',
            'trigger: on_bar',
            'filter:',
            '  - action: noop',
            '    when:',
            '      op: true',
            'entry:',
            '  - action: open_long',
            '    when:',
            '      op: gt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "100"',
            '    qty_pct: "0.5"',
            'exit:',
            '  - action: close_long',
            '    when:',
            '      op: lt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "100"',
            '    qty_pct: "1.0"',
            'risk:',
            '  cooldown_bars: 0',
            '',
        ]);
    }

    private function scaffold(): void
    {
        $now = now();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-dsl-rec-990090',
            'slug' => 'poc-dsl-rec-990090',
            'exchange_dek' => 'dummy-dek',
            'status' => 'active',
            'plan_code' => 'pro',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance-rec-dsl', 'name' => 'Binance',
            'base_url' => 'https://example.invalid', 'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'custom_dsl', 'name' => '自定义 DSL', 'category' => 'spot',
            'risk_profile' => 'balanced', 'default_params' => json_encode([]),
            'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('exchange_accounts')->insert([
            'id' => 990190,
            'tenant_id' => self::TENANT_ID,
            'exchange_id' => $exchangeId,
            'label' => 'poc-dsl-rec',
            'market' => 'spot',
            'encrypted_api_key' => 'poc',
            'encrypted_api_secret' => 'poc',
            'permissions' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('strategy_instances')->insert([
            'id' => self::INSTANCE_ID,
            'tenant_id' => self::TENANT_ID,
            'strategy_id' => $strategyId,
            'exchange_account_id' => 990190,
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'params' => json_encode(['dsl_script' => $this->script()]),
            'allocated_capital' => '1000',
            'status' => 'running',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
