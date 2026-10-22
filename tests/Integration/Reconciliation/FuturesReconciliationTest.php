<?php

declare(strict_types=1);

namespace Tests\Integration\Reconciliation;

use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Reconciliation\ReconciliationService;
use App\Jobs\RiskNotificationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\Fakes\FakeExchangeAdapter;
use Tests\Support\RedisCleanup;
use Tests\TestCase;

/**
 * 合约原生账实对账集成测试（spec 010 US1/US3，销技术债 A5）。
 *
 * 口径：交易所签名净持仓（getPositions 多腿合成）vs strategy_orders 成交签名净额。
 * SQLite + RefreshDatabase；交易所侧全部走 FakeExchangeAdapter，无任何外网请求。
 * 全类 redis 组：dedup 冷却与 unknown 连续计数依赖真实 Redis，服务缺失自动 skip。
 *
 * 铁律：只报告 + 暂停，绝不自动改账/补单/平仓；paused_by_breaker 不被覆盖。
 */
#[Group('redis')]
class FuturesReconciliationTest extends TestCase
{
    use ChecksRedisAvailability;
    use RedisCleanup;

    private ReconciliationService $service;

    /** @var array{tenant:int,instance:int,account:int} */
    private array $ids;

    private FakeExchangeAdapter $adapter;

    /** 2026-10-15 08:07:00 UTC（落在 0805 的 5m 桶内） */
    private int $t0;

    private int $orderSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->requireRedis();

        $this->t0 = gmmktime(8, 7, 0, 10, 15, 2026);
        $this->service = app(ReconciliationService::class);
        $this->ids = $this->scaffold();
        $this->clearReconcileRedisState();

        $this->adapter = new FakeExchangeAdapter;
        $this->adapter->tickerPrice = '60000';
        $this->adapter->balance = FakeExchangeAdapter::makeBalance('1000', '800', []);
    }

    protected function tearDown(): void
    {
        // 坑 #24：外部清理必须守卫且先于 parent::tearDown()
        $this->clearReconcileRedisState();

        parent::tearDown();
    }

    /**
     * 清理对账相关 Redis 状态：告警 dedup 槽 + unknown 连续计数（逻辑名，坑 #26）。
     */
    private function clearReconcileRedisState(): void
    {
        $tid = $this->ids['tenant'] ?? 990020;
        $iid = $this->ids['instance'] ?? 990220;
        $keys = [];
        foreach (['critical', 'unknown_streak'] as $kind) {
            $keys[] = sprintf('tenant:%d:dedup:reconcile:%s', $tid, sha1($iid.'|'.$kind));
        }
        $keys[] = sprintf('tenant:%d:reconcile:%d:unknown', $tid, $iid);

        $this->cleanupRedis($keys);
    }

    public function test_ok_long_when_exchange_position_matches_orders(): void
    {
        $this->fillOrder('buy', '0.5');
        $this->adapter->setPositions([$this->leg('long', '0.5')]);

        $result = $this->service->runForInstance($this->ids['instance'], '5m', $this->t0, $this->adapter);

        static::assertSame('ok', $result['status']);
        $diff = json_decode((string) DB::table('reconciliation_reports')->where('id', $result['id'])->value('diff_json'), true);
        static::assertSame(0, bccomp($diff['position']['exchange_signed_qty'], '0.5', 8));
        static::assertSame(0, bccomp($diff['position']['orders_net_qty'], '0.5', 8));

        // 不暂停、无告警；保证金权益快照保留
        static::assertSame('running', DB::table('strategy_instances')->where('id', $this->ids['instance'])->value('status'));
        static::assertSame(0, DB::table('risk_notifications')->where('tenant_id', $this->ids['tenant'])->count());
        static::assertSame(1, DB::table('balance_snapshots')->where('exchange_account_id', $this->ids['account'])->count());
        Queue::assertNothingPushed();
    }

    public function test_ok_short_when_both_sides_negative(): void
    {
        // 做空：交易所 −0.5、账本 −0.5，双方皆负时 diff 在零点附近 → ok（无需方向特判）
        $this->fillOrder('sell', '0.5');
        $this->adapter->setPositions([$this->leg('short', '0.5')]);

        $result = $this->service->runForInstance($this->ids['instance'], '5m', $this->t0, $this->adapter);

        static::assertSame('ok', $result['status']);
        $diff = json_decode((string) DB::table('reconciliation_reports')->where('id', $result['id'])->value('diff_json'), true);
        static::assertSame(0, bccomp($diff['position']['exchange_signed_qty'], '-0.5', 8));
        static::assertSame(0, bccomp($diff['position']['orders_net_qty'], '-0.5', 8));
        static::assertSame(0, bccomp($diff['position']['diff'], '0', 12));
        static::assertSame('running', DB::table('strategy_instances')->where('id', $this->ids['instance'])->value('status'));
    }

    public function test_drift_when_exchange_has_more_position_does_not_pause(): void
    {
        // 交易所多 0.0005 BTC ≈ 30 USDT：超容差、低于严重阈值、方向为交易所多仓 → drift
        $this->fillOrder('buy', '0.5');
        $this->adapter->setPositions([$this->leg('long', '0.5005')]);

        $result = $this->service->runForInstance($this->ids['instance'], '5m', $this->t0, $this->adapter);

        static::assertSame('drift', $result['status']);
        static::assertSame('running', DB::table('strategy_instances')->where('id', $this->ids['instance'])->value('status'));
        static::assertSame(0, DB::table('risk_notifications')->where('tenant_id', $this->ids['tenant'])->count());
        Queue::assertNothingPushed();
    }

    public function test_critical_when_exchange_flat_but_ledger_holds_pauses_and_alerts(): void
    {
        // 交易所无持仓（legs=[]）vs 账本 +0.5 →「账本有、交易所无」critical + 暂停 + 通知落库
        $this->fillOrder('buy', '0.5');
        $this->adapter->setPositions([]);

        $result = $this->service->runForInstance($this->ids['instance'], '5m', $this->t0, $this->adapter);

        static::assertSame('critical', $result['status']);
        static::assertSame(
            'paused_by_reconcile',
            DB::table('strategy_instances')->where('id', $this->ids['instance'])->value('status'),
        );

        $notes = DB::table('risk_notifications')
            ->where('tenant_id', $this->ids['tenant'])
            ->where('instance_id', $this->ids['instance'])
            ->get(['channel', 'status']);
        static::assertGreaterThanOrEqual(2, $notes->count()); // log + telegram
        foreach ($notes as $note) {
            static::assertSame('queued', $note->status);
            static::assertContains($note->channel, ['log', 'telegram']);
        }
        Queue::assertPushed(RiskNotificationJob::class, 1);
    }

    public function test_critical_when_exchange_position_reversed_pauses(): void
    {
        // 交易所 −0.5（short 腿）vs 账本 +0.5 → diff −1.0 → critical + 暂停
        $this->fillOrder('buy', '0.5');
        $this->adapter->setPositions([$this->leg('short', '0.5')]);

        $result = $this->service->runForInstance($this->ids['instance'], '5m', $this->t0, $this->adapter);

        static::assertSame('critical', $result['status']);
        $diff = json_decode((string) DB::table('reconciliation_reports')->where('id', $result['id'])->value('diff_json'), true);
        static::assertSame(0, bccomp($diff['position']['diff'], '-1.0', 8));
        static::assertSame(
            'paused_by_reconcile',
            DB::table('strategy_instances')->where('id', $this->ids['instance'])->value('status'),
        );
    }

    public function test_unknown_on_positions_failure_streaks_alerts_and_recovers(): void
    {
        $key = "tenant:{$this->ids['tenant']}:reconcile:{$this->ids['instance']}:unknown";
        $this->cleanupRedis([$key]);

        $this->adapter->throwOnGetPositions = true;
        $first = $this->service->runForInstance($this->ids['instance'], '5m', $this->t0, $this->adapter);
        static::assertSame('unknown', $first['status']);

        // contracts C4：unknown 报告 diff 必含 error 与 market 标识
        $diff = json_decode((string) DB::table('reconciliation_reports')->where('id', $first['id'])->value('diff_json'), true);
        static::assertSame('futures', $diff['market']);
        static::assertArrayHasKey('error', $diff);

        $this->service->runForInstance($this->ids['instance'], '5m', $this->t0 + 60, $this->adapter);
        static::assertSame(
            0,
            DB::table('risk_notifications')->where('tenant_id', $this->ids['tenant'])->count(),
        );

        // 第 3 次连续 unknown → 告警；实例始终不暂停
        $this->service->runForInstance($this->ids['instance'], '5m', $this->t0 + 120, $this->adapter);
        static::assertGreaterThanOrEqual(
            2,
            DB::table('risk_notifications')->where('tenant_id', $this->ids['tenant'])->count(),
        );
        static::assertSame('running', DB::table('strategy_instances')->where('id', $this->ids['instance'])->value('status'));

        // 恢复正常对账后连续计数清零
        $this->adapter->throwOnGetPositions = false;
        $this->fillOrder('buy', '0.5');
        $this->adapter->setPositions([$this->leg('long', '0.5')]);
        $this->service->runForInstance($this->ids['instance'], '5m', $this->t0 + 180, $this->adapter);
        static::assertSame(0, (int) Redis::connection('default')->get($key));
        $this->cleanupRedis([$key]);
    }

    public function test_ticker_failure_degrades_to_qty_dimension(): void
    {
        // ticker 失败不致命：price='0' 降级，等值容差/严重阈值退化为仅按数量分级
        $this->fillOrder('buy', '0.5');
        $this->adapter->setPositions([$this->leg('long', '0.5005')]); // diff +0.0005
        $this->adapter->throwOnTicker = true;

        $result = $this->service->runForInstance($this->ids['instance'], '5m', $this->t0, $this->adapter);

        static::assertSame('drift', $result['status']);
        $diff = json_decode((string) DB::table('reconciliation_reports')->where('id', $result['id'])->value('diff_json'), true);
        static::assertSame('0', $diff['price']);
        static::assertSame('running', DB::table('strategy_instances')->where('id', $this->ids['instance'])->value('status'));
    }

    public function test_critical_does_not_override_breaker_paused_instance(): void
    {
        // 暂停态与熔断态正交：paused_by_breaker 实例只出报告不改状态，差异仍告警审计
        DB::table('strategy_instances')->where('id', $this->ids['instance'])->update(['status' => 'paused_by_breaker']);
        $this->fillOrder('buy', '0.5');
        $this->adapter->setPositions([]);

        $result = $this->service->runForInstance($this->ids['instance'], '5m', $this->t0, $this->adapter);

        static::assertSame('critical', $result['status']);
        static::assertSame(
            'paused_by_breaker',
            DB::table('strategy_instances')->where('id', $this->ids['instance'])->value('status'),
        );
        static::assertGreaterThanOrEqual(
            2,
            DB::table('risk_notifications')->where('tenant_id', $this->ids['tenant'])->count(),
        );
    }

    public function test_rerun_same_period_is_idempotent_single_row_and_no_duplicate_alert(): void
    {
        $this->fillOrder('buy', '0.5');
        $this->adapter->setPositions([]);

        $first = $this->service->runForInstance($this->ids['instance'], '5m', $this->t0, $this->adapter);
        static::assertSame('critical', $first['status']);
        $notesAfterFirst = DB::table('risk_notifications')->where('tenant_id', $this->ids['tenant'])->count();
        static::assertGreaterThanOrEqual(2, $notesAfterFirst);

        // 同周期重跑：更新同一行而非新增；dedup 冷却阻断重复告警
        $second = $this->service->runForInstance($this->ids['instance'], '5m', $this->t0, $this->adapter);

        static::assertSame($first['id'], $second['id']);
        static::assertSame(
            1,
            DB::table('reconciliation_reports')
                ->where('strategy_instance_id', $this->ids['instance'])
                ->where('period_key', '20261015T0805')
                ->count(),
        );
        static::assertSame(
            $notesAfterFirst,
            DB::table('risk_notifications')->where('tenant_id', $this->ids['tenant'])->count(),
        );
        Queue::assertPushed(RiskNotificationJob::class, 1);
    }

    public function test_diff_json_futures_shape_and_string_amounts(): void
    {
        $this->fillOrder('buy', '0.5');
        $this->adapter->setPositions([$this->leg('long', '0.5')]);

        $result = $this->service->runForInstance($this->ids['instance'], '5m', $this->t0, $this->adapter);

        $diff = json_decode((string) DB::table('reconciliation_reports')->where('id', $result['id'])->value('diff_json'), true);

        // 顶层形态（data-model.md futures schema）
        static::assertSame(['market', 'asset', 'price', 'position', 'tolerance', 'critical_quote'], array_keys($diff));
        static::assertSame('futures', $diff['market']);
        static::assertSame('BTC', $diff['asset']);
        static::assertIsString($diff['price']);
        static::assertIsString($diff['critical_quote']);
        static::assertIsString($diff['tolerance']['qty']);
        static::assertIsString($diff['tolerance']['quote']);

        // position 维度：金额/数量字段恒为字符串（bcmath 约定）
        static::assertSame(
            ['exchange_signed_qty', 'orders_net_qty', 'diff', 'status', 'legs'],
            array_keys($diff['position']),
        );
        static::assertIsString($diff['position']['exchange_signed_qty']);
        static::assertIsString($diff['position']['orders_net_qty']);
        static::assertIsString($diff['position']['diff']);
        static::assertSame('ok', $diff['position']['status']);

        // legs 明细：恒为数组，逐腿字段齐备
        static::assertIsArray($diff['position']['legs']);
        static::assertCount(1, $diff['position']['legs']);
        $leg = $diff['position']['legs'][0];
        static::assertSame(
            ['side', 'size', 'entry_price', 'mark_price', 'leverage', 'liquidation_price', 'unrealized_pnl'],
            array_keys($leg),
        );
        static::assertSame('long', $leg['side']);
        static::assertIsString($leg['size']);
        static::assertIsString($leg['entry_price']);
        static::assertIsString($leg['mark_price']);
        static::assertSame(5, $leg['leverage']);
        static::assertIsString($leg['liquidation_price']);
        static::assertIsString($leg['unrealized_pnl']);
    }

    public function test_ok_hedge_mode_long_and_short_legs_net_to_match_orders(): void
    {
        // hedge 双向持仓（spec 010 US3，FR-008/SC-005）：交易所 long 0.5 + short 0.2
        // 签名合成 +0.3，与账本净额（buy 0.5 − sell 0.2）一致 → ok
        $this->fillOrder('buy', '0.5');
        $this->fillOrder('sell', '0.2');
        $this->adapter->setPositions([$this->leg('long', '0.5'), $this->leg('short', '0.2')]);

        $result = $this->service->runForInstance($this->ids['instance'], '5m', $this->t0, $this->adapter);

        static::assertSame('ok', $result['status']);
        $diff = json_decode((string) DB::table('reconciliation_reports')->where('id', $result['id'])->value('diff_json'), true);
        static::assertSame(0, bccomp($diff['position']['exchange_signed_qty'], '0.3', 8));
        static::assertSame(0, bccomp($diff['position']['orders_net_qty'], '0.3', 8));
        static::assertCount(2, $diff['position']['legs']);
        static::assertSame('long', $diff['position']['legs'][0]['side']);
        static::assertSame('short', $diff['position']['legs'][1]['side']);
        static::assertSame('running', DB::table('strategy_instances')->where('id', $this->ids['instance'])->value('status'));
        Queue::assertNothingPushed();
    }

    /**
     * 注入一条 filled 成交单（buy=+qty / sell=−qty 签名进账本净额）。
     */
    private function fillOrder(string $side, string $qty): void
    {
        $now = now();
        $seq = ++$this->orderSeq;
        DB::table('strategy_orders')->insert([
            'tenant_id' => $this->ids['tenant'], 'strategy_instance_id' => $this->ids['instance'],
            'exchange_order_id' => 'x-fut-'.$seq, 'client_order_id' => 'c-fut-'.$seq,
            'symbol' => 'BTCUSDT', 'side' => $side, 'type' => 'market', 'quantity' => $qty,
            'status' => 'filled', 'filled_qty' => $qty, 'avg_fill_price' => '60000', 'fee' => '0',
            'placed_at' => $now, 'filled_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * 构造一条持仓腿 DTO（对账仅消费 side/size，其余字段原样进 diff 明细）。
     */
    private function leg(string $side, string $size): ExchangePosition
    {
        return new ExchangePosition(
            'BTCUSDT', $side, $size, '59000', '60000', '45000', 5, '100', '500',
        );
    }

    /**
     * 最小单租户合约脚手架：exchange / strategy / futures 账户 / running 实例。
     * 账本完全由 strategy_orders 成交净额构成（测试内按需 fillOrder）。
     *
     * @return array{tenant:int,instance:int,account:int}
     */
    private function scaffold(): array
    {
        $now = now();

        $tenant = 990020;
        DB::table('tenants')->insert([
            'id' => $tenant, 'name' => 'POC recon-futures-990020', 'slug' => 'poc-recon-fut-990020',
            'exchange_dek' => 'dummy-dek', 'status' => 'active', 'plan_code' => 'pro',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance', 'name' => 'Binance',
            'base_url' => 'https://example.invalid', 'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot', 'futures']), 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'futures_grid', 'name' => '合约网格', 'category' => 'futures',
            'risk_profile' => 'conservative', 'default_params' => json_encode([]),
            'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $account = (int) DB::table('exchange_accounts')->insertGetId([
            'id' => 990120, 'tenant_id' => $tenant, 'exchange_id' => $exchangeId, 'label' => 'poc-fut',
            'market' => 'futures', 'encrypted_api_key' => 'k', 'encrypted_api_secret' => 's',
            'dek_version' => 1, 'permissions' => json_encode(['futures_trading']), 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $instance = (int) DB::table('strategy_instances')->insertGetId([
            'id' => 990220, 'tenant_id' => $tenant, 'strategy_id' => $strategyId,
            'exchange_account_id' => $account, 'symbol' => 'BTCUSDT', 'interval' => '1m',
            'params' => json_encode([]), 'allocated_capital' => '1000', 'status' => 'running',
            'breaker_state' => 'closed', 'position_snapshot' => json_encode([]), 'realized_pnl' => '0',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['tenant' => $tenant, 'instance' => $instance, 'account' => $account];
    }
}
