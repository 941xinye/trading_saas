<?php

declare(strict_types=1);

namespace Tests\Integration\Reconciliation;

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
 * 账实对账编排集成测试（P5.5 步骤 1 验收 #2、#3、#5-#8）。
 *
 * SQLite + RefreshDatabase；交易所侧全部走 FakeExchangeAdapter，无任何外网请求。
 * unknown 连续计数用 Redis 的用例标 redis 组，服务缺失自动 skip。
 */
class ReconciliationServiceTest extends TestCase
{
    use ChecksRedisAvailability;
    use RedisCleanup;

    private ReconciliationService $service;

    /** @var array{tenant_a:int,tenant_b:int,instance_a:int,instance_b:int,account_a:int,account_b:int} */
    private array $ids;

    private FakeExchangeAdapter $adapterA;

    private FakeExchangeAdapter $adapterB;

    /** 2026-10-15 08:07:00 UTC（落在 0805 的 5m 桶内） */
    private int $t0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->t0 = gmmktime(8, 7, 0, 10, 15, 2026);
        $this->service = app(ReconciliationService::class);
        $ids = $this->scaffold();
        $this->ids = $ids;
        $this->clearReconcileRedisState($ids);

        $this->adapterA = new FakeExchangeAdapter;
        $this->adapterA->tickerPrice = '60000';
        $this->adapterA->balance = FakeExchangeAdapter::makeBalance('900', '900', [
            'USDT' => ['total' => '900', 'available' => '900'],
            'BTC' => ['total' => '0.003', 'available' => '0.003'],
        ]);

        $this->adapterB = new FakeExchangeAdapter;
        $this->adapterB->tickerPrice = '60000';
        $this->adapterB->balance = FakeExchangeAdapter::makeBalance('900', '900', [
            'USDT' => ['total' => '900', 'available' => '900'],
            'BTC' => ['total' => '0.003', 'available' => '0.003'],
        ]);
    }

    protected function tearDown(): void
    {
        // 坑 #24：外部清理必须守卫且先于 parent::tearDown()；Redis 关闭时 notify fail-open 本来就不建键
        $this->clearReconcileRedisState($this->ids ?? [
            'tenant_a' => 990010, 'tenant_b' => 990011,
            'instance_a' => 990210, 'instance_b' => 990211,
        ]);

        parent::tearDown();
    }

    /**
     * 清理对账相关 Redis 状态：告警 dedup 槽（同实例同类告警 15 分钟 TTL，
     * 不清理会导致全量连跑时后续用例被误判 deduped 而 0 行通知）+ unknown 连续计数。
     *
     * @param  array{tenant_a?:int,tenant_b?:int,instance_a?:int,instance_b?:int}  $ids
     */
    private function clearReconcileRedisState(array $ids): void
    {
        $keys = [];
        foreach ([[$ids['tenant_a'] ?? null, $ids['instance_a'] ?? null], [$ids['tenant_b'] ?? null, $ids['instance_b'] ?? null]] as [$tid, $iid]) {
            if ($tid === null || $iid === null) {
                continue;
            }
            foreach (['critical', 'unknown_streak'] as $kind) {
                $keys[] = sprintf('tenant:%d:dedup:reconcile:%s', $tid, sha1($iid.'|'.$kind));
            }
            $keys[] = sprintf('tenant:%d:reconcile:%d:unknown', $tid, $iid);
        }

        $this->cleanupRedis($keys);
    }

    public function test_ok_when_ledger_matches_exchange(): void
    {
        $result = $this->service->runForInstance($this->ids['instance_a'], '5m', $this->t0, $this->adapterA);

        static::assertSame('ok', $result['status']);

        $report = DB::table('reconciliation_reports')->where('id', $result['id'])->first();
        $diff = json_decode((string) $report->diff_json, true);
        static::assertSame('ok', $report->status);
        // bcmath 字符串可能带 scale 尾零（0.000000000000），数值断言统一用 bccomp
        static::assertSame(0, bccomp($diff['base_qty']['diff'], '0', 12));
        static::assertSame(0, bccomp($diff['orders_net_qty']['diff'], '0', 12));
        static::assertSame(0, bccomp($diff['base_qty']['ledger'], '0.003', 8));
        static::assertSame(0, bccomp($diff['base_qty']['exchange'], '0.003', 8));
        static::assertSame(0, bccomp($diff['cost_basis']['ledger_cost'], '182', 8));

        // 实例不暂停；每次成功对账留一条净值快照（A3 真实净值口径数据）
        static::assertSame('running', DB::table('strategy_instances')->where('id', $this->ids['instance_a'])->value('status'));
        static::assertSame(1, DB::table('balance_snapshots')->where('exchange_account_id', $this->ids['account_a'])->count());
        Queue::assertNothingPushed();
    }

    public function test_drift_when_exchange_has_more_coins_does_not_pause(): void
    {
        // 交易所多 0.0003 BTC ≈ 18 USDT：超容差、低于严重阈值、方向为多币 → drift
        $this->adapterA->balance = FakeExchangeAdapter::makeBalance('900', '900', [
            'USDT' => ['total' => '900', 'available' => '900'],
            'BTC' => ['total' => '0.0033', 'available' => '0.0033'],
        ]);

        $result = $this->service->runForInstance($this->ids['instance_a'], '5m', $this->t0, $this->adapterA);

        static::assertSame('drift', $result['status']);
        static::assertSame('running', DB::table('strategy_instances')->where('id', $this->ids['instance_a'])->value('status'));
        static::assertSame(0, DB::table('risk_notifications')->where('tenant_id', $this->ids['tenant_a'])->count());
    }

    public function test_critical_when_exchange_missing_coins_pauses_and_writes_audit(): void
    {
        // 交易所少 0.002 BTC ≈ 120 USDT（账本有、交易所无）→ critical + 暂停 + 通知落库
        $this->adapterA->balance = FakeExchangeAdapter::makeBalance('900', '900', [
            'USDT' => ['total' => '900', 'available' => '900'],
            'BTC' => ['total' => '0.001', 'available' => '0.001'],
        ]);

        $result = $this->service->runForInstance($this->ids['instance_a'], '5m', $this->t0, $this->adapterA);

        static::assertSame('critical', $result['status']);
        static::assertSame(
            'paused_by_reconcile',
            DB::table('strategy_instances')->where('id', $this->ids['instance_a'])->value('status'),
        );

        $notes = DB::table('risk_notifications')
            ->where('tenant_id', $this->ids['tenant_a'])
            ->where('instance_id', $this->ids['instance_a'])
            ->get(['channel', 'status']);
        static::assertGreaterThanOrEqual(2, $notes->count()); // log + telegram
        foreach ($notes as $note) {
            static::assertSame('queued', $note->status);
            static::assertContains($note->channel, ['log', 'telegram']);
        }
        Queue::assertPushed(RiskNotificationJob::class, 1);
    }

    public function test_dust_difference_stays_ok(): void
    {
        $this->adapterA->balance = FakeExchangeAdapter::makeBalance('900', '900', [
            'USDT' => ['total' => '900', 'available' => '900'],
            'BTC' => ['total' => '0.00300001', 'available' => '0.00300001'],
        ]);

        $result = $this->service->runForInstance($this->ids['instance_a'], '5m', $this->t0, $this->adapterA);

        static::assertSame('ok', $result['status']);
    }

    public function test_unknown_on_balance_failure_neither_pauses_nor_alerts_before_threshold(): void
    {
        $this->adapterA->throwOnBalance = true;

        $result = $this->service->runForInstance($this->ids['instance_a'], '5m', $this->t0, $this->adapterA);

        static::assertSame('unknown', $result['status']);
        static::assertSame('running', DB::table('strategy_instances')->where('id', $this->ids['instance_a'])->value('status'));
        static::assertSame(0, DB::table('balance_snapshots')->where('exchange_account_id', $this->ids['account_a'])->count());
        static::assertSame(
            0,
            DB::table('risk_notifications')->where('tenant_id', $this->ids['tenant_a'])->count(),
        );
        Queue::assertNothingPushed();
    }

    public function test_rerun_same_period_is_idempotent_single_row(): void
    {
        $first = $this->service->runForInstance($this->ids['instance_a'], '5m', $this->t0, $this->adapterA);

        // 同周期重跑，账本改成 drift：更新同一行而非新增
        $this->adapterA->balance = FakeExchangeAdapter::makeBalance('900', '900', [
            'USDT' => ['total' => '900', 'available' => '900'],
            'BTC' => ['total' => '0.0033', 'available' => '0.0033'],
        ]);
        $second = $this->service->runForInstance($this->ids['instance_a'], '5m', $this->t0, $this->adapterA);

        static::assertSame($first['id'], $second['id']);
        static::assertSame('drift', $second['status']);
        static::assertSame(
            1,
            DB::table('reconciliation_reports')
                ->where('strategy_instance_id', $this->ids['instance_a'])
                ->where('period_key', '20261015T0805')
                ->count(),
        );
    }

    public function test_multi_tenant_critical_does_not_leak_into_other_tenant(): void
    {
        // A 租户 critical
        $this->adapterA->balance = FakeExchangeAdapter::makeBalance('900', '900', [
            'USDT' => ['total' => '900', 'available' => '900'],
            'BTC' => ['total' => '0.001', 'available' => '0.001'],
        ]);
        $this->service->runForInstance($this->ids['instance_a'], '5m', $this->t0, $this->adapterA);

        // B 租户同周期 ok
        $this->service->runForInstance($this->ids['instance_b'], '5m', $this->t0, $this->adapterB);

        static::assertSame(
            'paused_by_reconcile',
            DB::table('strategy_instances')->where('id', $this->ids['instance_a'])->value('status'),
        );
        static::assertSame(
            'running',
            DB::table('strategy_instances')->where('id', $this->ids['instance_b'])->value('status'),
        );

        // B 租户视角看不到 A 的报告
        $bReports = DB::table('reconciliation_reports')->where('tenant_id', $this->ids['tenant_b'])->get();
        static::assertCount(1, $bReports);
        static::assertSame('ok', $bReports[0]->status);
        static::assertSame($this->ids['instance_b'], (int) $bReports[0]->strategy_instance_id);
    }

    public function test_critical_does_not_override_breaker_paused_instance(): void
    {
        DB::table('strategy_instances')->where('id', $this->ids['instance_a'])->update(['status' => 'paused_by_breaker']);
        $this->adapterA->balance = FakeExchangeAdapter::makeBalance('900', '900', [
            'USDT' => ['total' => '900', 'available' => '900'],
            'BTC' => ['total' => '0.001', 'available' => '0.001'],
        ]);

        $result = $this->service->runForInstance($this->ids['instance_a'], '5m', $this->t0, $this->adapterA);

        static::assertSame('critical', $result['status']);
        static::assertSame(
            'paused_by_breaker',
            DB::table('strategy_instances')->where('id', $this->ids['instance_a'])->value('status'),
        );
        // 差异仍然告警审计
        static::assertGreaterThanOrEqual(
            2,
            DB::table('risk_notifications')->where('tenant_id', $this->ids['tenant_a'])->count(),
        );
    }

    #[Group('redis')]
    public function test_unknown_streak_alerts_after_three_consecutive_failures(): void
    {
        $this->requireRedis();
        $redis = Redis::connection('default');
        $key = "tenant:{$this->ids['tenant_a']}:reconcile:{$this->ids['instance_a']}:unknown";
        $this->cleanupRedis([$key]);

        $this->adapterA->throwOnBalance = true;
        $this->service->runForInstance($this->ids['instance_a'], '5m', $this->t0, $this->adapterA);
        $this->service->runForInstance($this->ids['instance_a'], '5m', $this->t0 + 60, $this->adapterA);
        static::assertSame(
            0,
            DB::table('risk_notifications')->where('tenant_id', $this->ids['tenant_a'])->count(),
        );

        $this->service->runForInstance($this->ids['instance_a'], '5m', $this->t0 + 120, $this->adapterA);
        static::assertGreaterThanOrEqual(
            2,
            DB::table('risk_notifications')->where('tenant_id', $this->ids['tenant_a'])->count(),
        );

        // 一次成功对账后连续计数清零，后续重新计数
        $this->adapterA->throwOnBalance = false;
        $this->service->runForInstance($this->ids['instance_a'], '5m', $this->t0 + 180, $this->adapterA);
        static::assertSame(0, (int) $redis->get($key));
        $this->cleanupRedis([$key]);
    }

    /**
     * 护栏：合约实例不进入对账范围（现货语义会误判 critical 误暂停，2026-10-22）。
     */
    public function test_reconcileable_instance_ids_excludes_futures_market(): void
    {
        $now = now();
        $exchangeId = (int) DB::table('exchange_accounts')->where('id', $this->ids['account_a'])->value('exchange_id');
        $strategyId = (int) DB::table('strategy_instances')->where('id', $this->ids['instance_a'])->value('strategy_id');

        $futuresAccount = (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => $this->ids['tenant_a'], 'exchange_id' => $exchangeId, 'label' => 'poc-futures',
            'market' => 'futures', 'encrypted_api_key' => 'k', 'encrypted_api_secret' => 's',
            'dek_version' => 1, 'permissions' => json_encode(['futures_trading']), 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $futuresInstance = (int) DB::table('strategy_instances')->insertGetId([
            'tenant_id' => $this->ids['tenant_a'], 'strategy_id' => $strategyId,
            'exchange_account_id' => $futuresAccount, 'symbol' => 'ETHUSDT', 'interval' => '1m',
            'params' => json_encode([]), 'allocated_capital' => '1000', 'status' => 'running',
            'breaker_state' => 'closed', 'position_snapshot' => json_encode([]), 'realized_pnl' => '0',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $ids = $this->service->reconcileableInstanceIds();

        static::assertContains($this->ids['instance_a'], $ids);
        static::assertContains($this->ids['instance_b'], $ids);
        static::assertNotContains($futuresInstance, $ids);
    }

    /**
     * 最小双租户脚手架：exchanges / strategies / accounts / instances / 账本快照 / 成交单。
     *
     * @return array{tenant_a:int,tenant_b:int,instance_a:int,instance_b:int,account_a:int,account_b:int}
     */
    private function scaffold(): array
    {
        $now = now();

        $tenantA = 990010;
        $tenantB = 990011;
        foreach ([[$tenantA, 'poc-recon-990010'], [$tenantB, 'poc-recon-990011']] as [$tid, $slug]) {
            DB::table('tenants')->insert([
                'id' => $tid, 'name' => "POC {$slug}", 'slug' => $slug,
                'exchange_dek' => 'dummy-dek', 'status' => 'active', 'plan_code' => 'pro',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance', 'name' => 'Binance',
            'base_url' => 'https://example.invalid', 'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']), 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'spot_grid', 'name' => '现货网格', 'category' => 'spot',
            'risk_profile' => 'conservative', 'default_params' => json_encode([]),
            'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $accountA = (int) DB::table('exchange_accounts')->insertGetId([
            'id' => 990110, 'tenant_id' => $tenantA, 'exchange_id' => $exchangeId, 'label' => 'poc-a',
            'market' => 'spot', 'encrypted_api_key' => 'k', 'encrypted_api_secret' => 's',
            'dek_version' => 1, 'permissions' => json_encode(['spot_trading']), 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $accountB = (int) DB::table('exchange_accounts')->insertGetId([
            'id' => 990111, 'tenant_id' => $tenantB, 'exchange_id' => $exchangeId, 'label' => 'poc-b',
            'market' => 'spot', 'encrypted_api_key' => 'k', 'encrypted_api_secret' => 's',
            'dek_version' => 1, 'permissions' => json_encode(['spot_trading']), 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // 账本：两格持仓 0.001@60000 + 0.002@61000 = 0.003 BTC / 182 USDT 成本
        $snapshot = json_encode([
            'grid_step' => '500',
            'last_index' => 5,
            'grids' => [
                '4' => ['qty' => '0.001', 'cost' => '60000', 'pending' => false],
                '5' => ['qty' => '0.002', 'cost' => '61000', 'pending' => false],
            ],
        ]);

        $instanceA = (int) DB::table('strategy_instances')->insertGetId([
            'id' => 990210, 'tenant_id' => $tenantA, 'strategy_id' => $strategyId,
            'exchange_account_id' => $accountA, 'symbol' => 'BTCUSDT', 'interval' => '1m',
            'params' => json_encode(['lower_price' => '58000', 'upper_price' => '63000', 'grid_count' => 10]),
            'allocated_capital' => '1000', 'status' => 'running', 'breaker_state' => 'closed',
            'position_snapshot' => $snapshot, 'realized_pnl' => '0',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $instanceB = (int) DB::table('strategy_instances')->insertGetId([
            'id' => 990211, 'tenant_id' => $tenantB, 'strategy_id' => $strategyId,
            'exchange_account_id' => $accountB, 'symbol' => 'BTCUSDT', 'interval' => '1m',
            'params' => json_encode(['lower_price' => '58000', 'upper_price' => '63000', 'grid_count' => 10]),
            'allocated_capital' => '1000', 'status' => 'running', 'breaker_state' => 'closed',
            'position_snapshot' => $snapshot, 'realized_pnl' => '0',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // strategy_orders 成交净额与账本一致：买单合计 0.003
        foreach ([['990210-1', $tenantA, $instanceA, $accountA, '0.001'], ['990210-2', $tenantA, $instanceA, $accountA, '0.002']] as [$cid, $tid, $iid, $aid, $qty]) {
            DB::table('strategy_orders')->insert([
                'tenant_id' => $tid, 'strategy_instance_id' => $iid,
                'exchange_order_id' => 'x-'.$cid, 'client_order_id' => $cid,
                'symbol' => 'BTCUSDT', 'side' => 'buy', 'type' => 'market', 'quantity' => $qty,
                'status' => 'filled', 'filled_qty' => $qty, 'avg_fill_price' => '60000', 'fee' => '0',
                'placed_at' => $now, 'filled_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ([['990211-1', $tenantB, $instanceB, $accountB, '0.001'], ['990211-2', $tenantB, $instanceB, $accountB, '0.002']] as [$cid, $tid, $iid, $aid, $qty]) {
            DB::table('strategy_orders')->insert([
                'tenant_id' => $tid, 'strategy_instance_id' => $iid,
                'exchange_order_id' => 'x-'.$cid, 'client_order_id' => $cid,
                'symbol' => 'BTCUSDT', 'side' => 'buy', 'type' => 'market', 'quantity' => $qty,
                'status' => 'filled', 'filled_qty' => $qty, 'avg_fill_price' => '60000', 'fee' => '0',
                'placed_at' => $now, 'filled_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return [
            'tenant_a' => $tenantA, 'tenant_b' => $tenantB,
            'instance_a' => $instanceA, 'instance_b' => $instanceB,
            'account_a' => $accountA, 'account_b' => $accountB,
        ];
    }
}
