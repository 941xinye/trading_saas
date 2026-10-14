<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\EntitlementException;
use App\Domain\Billing\Metering\QuotaExceededException;
use App\Domain\Billing\Metering\TwoPhaseMeter;
use App\Domain\Billing\Plans\PlanService;
use App\Domain\Billing\QuotaGuard;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeBalance;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Signal\SignalPipeline;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantKeyResolver;
use App\Models\MeterEvent;
use App\Models\SignalSubscription;
use App\Models\Subscription;
use App\Support\AesGcmCrypt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * P3 POC 离线全链路断言：
 *  1. PlanService 套餐解析/白名单/未知套餐拒绝
 *  2. TwoPhaseMeter 两阶段（freeze/settle/reverse/ref 幂等/reconcile）
 *  3. 并发原子占额（10 进程抢 3 配额，恰好 3 行 frozen）
 *  4. SubscriptionService 状态机（trial→past_due→suspended→恢复→canceled）
 *  5. AI 配额耗尽/权益失效 → pipeline 走 rule_fallback（不投 HTTP）
 *  6. OrderExecutor 配额/权益拦截零交易所调用；失败冲正还额
 *  7. 资源数/渠道门（含 poc:signal-setup 端到端拒绝/放行）
 *  8. DEK 轮换（新旧密文、抽验、租户间互不影响）
 *  9. 租户隔离（TenantScope 双向不可见）
 *
 * 全程使用一次性租户 poc-verify-billing-a/b，结束自动清理（行 + Redis 计数桶）。
 */
class PocVerifyBillingCommand extends Command
{
    protected $signature = 'poc:verify-billing';

    protected $description = 'POC: offline end-to-end assertions for P3 billing/quota/tenancy';

    private int $pass = 0;

    private int $fail = 0;

    /** @var string[] */
    private array $failures = [];

    private int $tenantA;

    private int $tenantB;

    private int $accountA;

    private int $accountB;

    private int $instanceA;

    public function handle(
        PlanService $plans,
        TwoPhaseMeter $meter,
        QuotaGuard $guard,
        SubscriptionService $subscriptions,
        TenantKeyResolver $keyResolver,
        AesGcmCrypt $crypt,
        SignalPipeline $pipeline,
    ): int {
        $this->info('==== P3 多租户计费 POC 验证 ====');

        try {
            $this->provisionFixtures($keyResolver, $crypt);

            $this->checkPlanService($plans);
            $this->checkTwoPhaseMeter($meter);
            $this->checkConcurrency($meter);
            $this->checkSubscriptionLifecycle($subscriptions, $guard);
            $this->checkAiQuotaFallback($pipeline, $guard, $meter);
            $this->checkOrderQuota($guard, $meter);
            $this->checkResourceGates($guard);
            $this->checkDekRotation($keyResolver, $crypt);
            $this->checkTenantIsolation();
        } catch (Throwable $e) {
            $this->fail++;
            $this->failures[] = '验证流程异常中断：' . $e->getMessage();
            $this->error('FATAL: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
        } finally {
            $this->cleanupFixtures();
        }

        $this->newLine();
        $this->info(sprintf('RESULT: %d/%d passed', $this->pass, $this->pass + $this->fail));
        if ($this->fail > 0) {
            $this->error('VERIFY FAILED');
            foreach ($this->failures as $f) {
                $this->line('  - ' . $f);
            }

            return self::FAILURE;
        }

        $this->info('ALL CHECKS PASSED');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // 断言助手
    // ------------------------------------------------------------------

    private function check(bool $cond, string $label, string $detail = ''): void
    {
        if ($cond) {
            $this->pass++;
            $this->line('  PASS ' . $label);
        } else {
            $this->fail++;
            $this->failures[] = $label . ($detail !== '' ? "（{$detail}）" : '');
            $this->error('  FAIL ' . $label . ($detail !== '' ? "（{$detail}）" : ''));
        }
    }

    // ------------------------------------------------------------------
    // 一次性夹具
    // ------------------------------------------------------------------

    private function provisionFixtures(TenantKeyResolver $keyResolver, AesGcmCrypt $crypt): void
    {
        // 幂等：重跑先清旧租户（slug 唯一）
        foreach (['poc-verify-billing-a', 'poc-verify-billing-b'] as $slug) {
            $old = DB::table('tenants')->where('slug', $slug)->first();
            if ($old !== null) {
                $this->deleteTenantCascade((int) $old->id);
            }
        }

        $exchangeId = (int) DB::table('exchanges')->where('code', 'binance')->value('id');
        if ($exchangeId === 0) {
            $exchangeId = (int) DB::table('exchanges')->insertGetId([
                'code' => 'binance', 'name' => 'Binance',
                'base_url' => 'https://api.binance.com',
                'ws_url' => 'wss://stream.binance.com:9443/stream',
                'markets' => json_encode(['spot', 'futures']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $strategyId = (int) DB::table('strategies')->where('code', 'spot_grid')->value('id');
        if ($strategyId === 0) {
            $strategyId = (int) DB::table('strategies')->insertGetId([
                'code' => 'spot_grid', 'name' => '现货网格', 'category' => 'spot',
                'risk_profile' => 'conservative',
                'default_params' => json_encode([]), 'enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->tenantA = $this->createTenantWithAccount($keyResolver, $crypt, 'poc-verify-billing-a', 'free', $exchangeId, 'poc-key-A', 'poc-secret-A');
        $this->tenantB = $this->createTenantWithAccount($keyResolver, $crypt, 'poc-verify-billing-b', 'free', $exchangeId, 'poc-key-B', 'poc-secret-B');
        $this->accountA = (int) DB::table('exchange_accounts')->where('tenant_id', $this->tenantA)->value('id');
        $this->accountB = (int) DB::table('exchange_accounts')->where('tenant_id', $this->tenantB)->value('id');

        $this->instanceA = (int) DB::table('strategy_instances')->insertGetId([
            'tenant_id' => $this->tenantA,
            'strategy_id' => $strategyId,
            'exchange_account_id' => $this->accountA,
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'params' => json_encode([]),
            'allocated_capital' => '100',
            'status' => 'stopped',
            'started_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->flushMeterKeys($this->tenantA);
        $this->flushMeterKeys($this->tenantB);
    }

    private function createTenantWithAccount(
        TenantKeyResolver $keyResolver,
        AesGcmCrypt $crypt,
        string $slug,
        string $plan,
        int $exchangeId,
        string $apiKeyPlain,
        string $secretPlain,
    ): int {
        $tenantId = (int) DB::table('tenants')->insertGetId([
            'name' => $slug,
            'slug' => $slug,
            'exchange_dek' => '',
            'status' => 'active',
            'plan_code' => $plan,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dek = $keyResolver->tenantDek($tenantId);
        DB::table('exchange_accounts')->insert([
            'tenant_id' => $tenantId,
            'exchange_id' => $exchangeId,
            'label' => 'poc',
            'market' => 'spot',
            'encrypted_api_key' => $crypt->encrypt($apiKeyPlain, $dek),
            'encrypted_api_secret' => $crypt->encrypt($secretPlain, $dek),
            'permissions' => json_encode(['spot']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    // ------------------------------------------------------------------
    // 1. PlanService
    // ------------------------------------------------------------------

    private function checkPlanService(PlanService $plans): void
    {
        $this->info(PHP_EOL . '---- 1. PlanService ----');
        $a = $plans->tenant($this->tenantA);

        $this->check($plans->limit($a, 'ai_calls_per_day') === 10, 'free ai_calls_per_day=10（实际：' . $plans->limit($a, 'ai_calls_per_day') . '）');
        $this->check($plans->limit($a, 'max_signal_subscriptions') === 1, 'free max_signal_subscriptions=1');
        $this->check($plans->allowsChannel($a, 'log'), 'log 系统渠道始终放行');
        $this->check($plans->allowsChannel($a, 'websocket') && ! $plans->allowsChannel($a, 'telegram'), 'free 渠道白名单 websocket 放行/telegram 拒绝');
        $this->check($plans->allowsStrategy($a, 'spot_grid') && ! $plans->allowsStrategy($a, 'futures_grid'), 'free 策略白名单 spot_grid 放行/futures_grid 拒绝');

        DB::table('tenants')->where('id', $this->tenantB)->update(['plan_code' => 'pro']);
        $b = $plans->tenant($this->tenantB);
        $this->check($plans->limit($b, 'ai_calls_per_day') === 500 && $plans->allowsChannel($b, 'telegram'), 'pro 套餐 500/天 + telegram 白名单');
        DB::table('tenants')->where('id', $this->tenantB)->update(['plan_code' => 'free']);

        DB::table('tenants')->where('id', $this->tenantB)->update(['plan_code' => 'ghost-plan']);
        $threw = false;
        try {
            $plans->planConfig($plans->tenant($this->tenantB));
        } catch (Throwable) {
            $threw = true;
        }
        $this->check($threw, '未知 plan_code 抛异常（不静默降级 free）');
        DB::table('tenants')->where('id', $this->tenantB)->update(['plan_code' => 'free']);
    }

    // ------------------------------------------------------------------
    // 2. TwoPhaseMeter
    // ------------------------------------------------------------------

    private function checkTwoPhaseMeter(TwoPhaseMeter $meter): void
    {
        $this->info(PHP_EOL . '---- 2. TwoPhaseMeter 两阶段 ----');
        $t = $this->tenantA;

        $this->check($meter->usedToday($t, 'ai_call') === 0, '初始当日用量=0');

        $e1 = $meter->freeze($t, 'ai_call', 'v2-1', 10);
        $this->check($e1 !== null && $e1->status === 'frozen', 'freeze 成功且行 status=frozen');
        $this->check($meter->usedToday($t, 'ai_call') === 1, 'freeze 后用量=1');

        $e1Dup = $meter->freeze($t, 'ai_call', 'v2-1', 10);
        $this->check($e1Dup !== null && (int) $e1Dup->getKey() === (int) $e1->getKey(), '同 ref 重复 freeze 幂等返回同一行');
        $this->check($meter->usedToday($t, 'ai_call') === 1, '幂等 freeze 不双扣（用量仍=1）');

        $e1 = $meter->settle($e1);
        $this->check($e1->status === 'settled' && (string) $e1->settled_amount === '1.00000000', 'settle 后 status=settled');
        $this->check($meter->usedToday($t, 'ai_call') === 1, 'settle 不动计数');

        $e2 = $meter->freeze($t, 'ai_call', 'v2-2', 10);
        $e2 = $meter->reverse($e2);
        $this->check($e2->status === 'reversed', 'reverse 后 status=reversed');
        $this->check($meter->usedToday($t, 'ai_call') === 1, 'reverse 归还配额（用量回到=1）');

        // reconcile：模拟 Redis 丢 key
        $meter->flushDayCounter($t, 'ai_call');
        $this->check($meter->usedToday($t, 'ai_call') === 0, '删除 Redis key 后用量=0');
        $rebuilt = $meter->reconcile($t, 'ai_call');
        $this->check($rebuilt === 1 && $meter->usedToday($t, 'ai_call') === 1, 'reconcile 从 meter_events 重建=1（reversed 不计）');
    }

    // ------------------------------------------------------------------
    // 3. 并发原子占额
    // ------------------------------------------------------------------

    private function checkConcurrency(TwoPhaseMeter $meter): void
    {
        $this->info(PHP_EOL . '---- 3. 并发原子性（10 进程抢 3 配额）----');
        $t = $this->tenantA;

        // check2 结束时桶内仍有 1（settled 占额）；并发测试从干净桶开始
        $meter->flushDayCounter($t, 'ai_call');

        $processes = [];
        for ($i = 0; $i < 10; $i++) {
            $p = new Process([
                PHP_BINARY, base_path('artisan'), 'poc:meter-freeze',
                (string) $t, 'ai_call', '--limit=3', '--ref=c3-' . $i,
            ]);
            $p->start();
            $processes[] = $p;
        }
        $allowed = 0;
        $denied = 0;
        foreach ($processes as $p) {
            $p->wait();
            $out = trim($p->getOutput());
            if (str_starts_with($out, 'ALLOWED')) {
                $allowed++;
            } elseif (str_starts_with($out, 'DENIED')) {
                $denied++;
            }
        }

        $frozenRows = MeterEvent::query()->withoutGlobalScopes()
            ->where('tenant_id', $t)
            ->where('event_type', 'ai_call')
            ->where('ref_id', 'like', 'c3-%')
            ->where('status', 'frozen')
            ->count();

        $this->check($allowed === 3, "恰好 3 个进程占额成功（实际 ALLOWED={$allowed}）");
        $this->check($denied === 7, "恰好 7 个进程被拒（实际 DENIED={$denied}）");
        $this->check($frozenRows === 3, "meter_events 恰好 3 行 c3-* frozen（实际={$frozenRows}）");
    }

    // ------------------------------------------------------------------
    // 4. 订阅状态机
    // ------------------------------------------------------------------

    private function checkSubscriptionLifecycle(SubscriptionService $service, QuotaGuard $guard): void
    {
        $this->info(PHP_EOL . '---- 4. SubscriptionService 状态机 ----');
        $t = $this->tenantB;

        $sub = $service->provision($t, 'free', 14);
        $this->check($sub->status === 'trialing', 'provision(trial=14d) → trialing');
        $this->check($guard->isEntitled($t), 'trialing 期内权益正常');

        // 回拨到期日：trialing → past_due
        DB::table('subscriptions')->where('id', $sub->getKey())->update(['ends_at' => now()->subDay()]);
        $transitions = $service->refreshStatus();
        $fresh = $service->latestFor($t);
        $hit = $this->transitionHit($transitions, $t, 'past_due');
        $this->check($hit && $fresh->status === 'past_due', '试用到期 refresh → past_due');
        $this->check(! $guard->isEntitled($t), 'past_due 权益被拦截');

        // 超过宽限：past_due → tenant suspended
        DB::table('subscriptions')->where('id', $sub->getKey())->update(['ends_at' => now()->subDays(10)]);
        $service->refreshStatus();
        $tenantStatus = (string) DB::table('tenants')->where('id', $t)->value('status');
        $this->check($tenantStatus === 'suspended', '超过宽限 refresh → tenant suspended（实际：' . $tenantStatus . '）');
        $this->check(! $guard->isEntitled($t), 'suspended 权益被拦截');

        // 续费恢复：直接 active
        $restored = $service->provision($t, 'pro', 0);
        $this->check(
            $restored->status === 'active'
            && (string) DB::table('tenants')->where('id', $t)->value('status') === 'active'
            && (string) DB::table('tenants')->where('id', $t)->value('plan_code') === 'pro',
            '重新 provision(pro) → active + tenant active + plan_code=pro',
        );
        $this->check($guard->isEntitled($t), '恢复后权益正常');

        // 取消
        $service->cancel($t);
        $this->check($service->latestFor($t)->status === 'canceled' && ! $guard->isEntitled($t), 'cancel → canceled 且权益拦截');

        // 留给后续 check5/6 的 B 不需要权益；check8 轮换不要求 active
    }

    /**
     * @param array<int,array{tenant_id:int,from:string,to:string}> $transitions
     */
    private function transitionHit(array $transitions, int $tenantId, string $to): bool
    {
        foreach ($transitions as $tr) {
            if ($tr['tenant_id'] === $tenantId && $tr['to'] === $to) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // 5. AI 配额耗尽 / 权益失效 → 规则兜底
    // ------------------------------------------------------------------

    private function checkAiQuotaFallback(SignalPipeline $pipeline, QuotaGuard $guard, TwoPhaseMeter $meter): void
    {
        $this->info(PHP_EOL . '---- 5. AI 配额耗尽/权益失效兜底 ----');
        $t = $this->tenantA;
        $original = config('plans.plans.free.features.ai_calls_per_day');
        config(['plans.plans.free.features.ai_calls_per_day' => 1]);

        try {
            // signals.subscription_id 有 FK：用真实的 active=0 脚手架订阅（不计入资源数）
            $scaffoldId = (int) DB::table('signal_subscriptions')->insertGetId([
                'tenant_id' => $t,
                'name' => 'poc-verify-billing-quota',
                'symbols' => json_encode(['PBFTEST']),
                'intervals' => json_encode(['5m']),
                'ai_prompter' => 'deepseek',
                'ai_model' => null,
                'rule_fallback_enabled' => 1,
                'channels' => json_encode(['log']),
                'active' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // check2/3 已占用当日桶，重置后再模拟小配额
            $meter->flushDayCounter($t, 'ai_call');

            // 先占掉唯一配额
            $guard->freezeDaily($t, 'ai_call', 'pre-exhaust');
            $this->check($meter->usedToday($t, 'ai_call') === 1, '测试前置：free 档 1 个配额已占');

            $bars = $this->deterministicBullishBars();
            $latest = $bars[count($bars) - 1];
            $sub = $this->fakeSubscription($t, $scaffoldId);

            $startedAt = microtime(true);
            $signal = $pipeline->run($sub, $latest, $bars);
            $elapsed = microtime(true) - $startedAt;

            $this->check(
                $signal !== null && $signal->source === 'rule_fallback' && $signal->direction === 'long',
                '配额耗尽 pipeline 产出确定性 rule_fallback long（实际：' . ($signal?->source ?? 'null') . '）',
            );
            $this->check($elapsed < 5.0, sprintf('耗尽路径不投 HTTP（耗时 %.2fs < 5s）', $elapsed));

            $aiRows = MeterEvent::query()->withoutGlobalScopes()
                ->where('tenant_id', $t)->where('event_type', 'ai_call')
                ->where('ref_id', $scaffoldId . ':' . $latest->openTime)
                ->count();
            $this->check($aiRows === 0, '耗尽时不写 meter 行（实际行数=' . $aiRows . '）');

            // 权益失效：suspended → 同样兜底，不调用 AI（复用同一订阅行）
            DB::table('tenants')->where('id', $t)->update(['status' => 'suspended']);
            $signal2 = $pipeline->run($sub, $latest, $bars);
            $this->check(
                $signal2 !== null && $signal2->source === 'rule_fallback',
                'suspended 租户跳过 AI 走规则兜底（实际：' . ($signal2?->source ?? 'null') . '）',
            );
            DB::table('tenants')->where('id', $t)->update(['status' => 'active']);
        } finally {
            config(['plans.plans.free.features.ai_calls_per_day' => $original]);
        }
    }

    private function fakeSubscription(int $tenantId, int $id): object
    {
        return (object) [
            'id' => $id,
            'tenant_id' => $tenantId,
            'ai_prompter' => 'deepseek',
            'rule_fallback_enabled' => 1,
            'channels' => ['log'],
        ];
    }

    /**
     * 确定性多头形态：100 根走平@100 → 10 根每根-0.2 → 10 根每根+0.2
     * （末根 score=3：MA7 上穿 MA25 + MACD 红柱，P2 已验证参数不敏感）
     *
     * @return ExchangeKline[]
     */
    private function deterministicBullishBars(): array
    {
        $closes = array_fill(0, 100, 100.0);
        for ($i = 1; $i <= 10; $i++) {
            $closes[] = 100.0 - $i * 0.2;
        }
        $price = 100.0 - 2.0;
        for ($i = 0; $i < 10; $i++) {
            $price += 0.2;
            $closes[] = $price;
        }

        $base = intdiv(time() * 1000, 300_000) * 300_000 - 120 * 300_000;
        $bars = [];
        foreach ($closes as $i => $c) {
            $bars[] = new ExchangeKline(
                exchangeCode: 'binance',
                symbol: 'PBFTEST',
                interval: '5m',
                openTime: $base + $i * 300_000,
                closeTime: $base + $i * 300_000 + 299_999,
                open: (string) $c,
                high: (string) $c,
                low: (string) $c,
                close: (string) $c,
                volume: '1',
                quoteVolume: (string) $c,
                tradesCount: 1,
            );
        }

        return $bars;
    }

    // ------------------------------------------------------------------
    // 6. OrderExecutor 配额/权益
    // ------------------------------------------------------------------

    private function checkOrderQuota(QuotaGuard $guard, TwoPhaseMeter $meter): void
    {
        $this->info(PHP_EOL . '---- 6. OrderExecutor 下单配额 ----');
        $t = $this->tenantA;
        $instance = (object) ['id' => $this->instanceA, 'tenant_id' => $t, 'symbol' => 'BTCUSDT'];
        $executor = app(\App\Domain\Strategy\OrderExecutor::class);

        // (a) 配额为 0 → 拒绝，零交易所调用
        $original = config('plans.plans.free.features.orders_per_day');
        config(['plans.plans.free.features.orders_per_day' => 0]);
        $adapter = $this->stubAdapter();
        $order = $executor->place($t, $instance, $adapter, ['side' => 'buy', 'qty' => '0.01', 'price' => '100']);
        $this->check($order->status === 'failed', 'orders_per_day=0 时返回 failed');
        $this->check($adapter->calls === 0, '配额拒绝零交易所调用（实际 calls=' . $adapter->calls . '）');

        // (b) suspended → 拒绝，零交易所调用
        DB::table('tenants')->where('id', $t)->update(['status' => 'suspended']);
        $adapter = $this->stubAdapter();
        $order = $executor->place($t, $instance, $adapter, ['side' => 'buy', 'qty' => '0.01', 'price' => '100']);
        $this->check($order->status === 'failed', 'suspended 时返回 failed');
        $this->check($adapter->calls === 0, '权益拒绝零交易所调用');
        DB::table('tenants')->where('id', $t)->update(['status' => 'active']);

        // (c) 正常下单 → filled，meter settled
        config(['plans.plans.free.features.orders_per_day' => $original]);
        $meter->flushDayCounter($t, 'order_placed');
        $adapter = $this->stubAdapter();
        $order = $executor->place($t, $instance, $adapter, ['side' => 'buy', 'qty' => '0.01', 'price' => '100']);
        $this->check($order->status === 'filled' && $adapter->calls === 1, '正常下单 filled 且交易所被调用 1 次');
        $this->check($meter->usedToday($t, 'order_placed') === 1, '成交后 order_placed 用量=1');

        // (d) 数量对齐为 0 → failed + 冲正，计数不增加
        $adapter2 = $this->stubAdapter($adapter->lastClientOrderId);
        $order = $executor->place($t, $instance, $adapter2, ['side' => 'buy', 'qty' => '0.00000001', 'price' => '100']);
        $this->check($order->status === 'failed', '对齐后数量为 0 → failed');
        $this->check($adapter2->calls === 0, '失败路径零交易所调用');
        $this->check($meter->usedToday($t, 'order_placed') === 1, '冲正后用量仍=1（实际=' . $meter->usedToday($t, 'order_placed') . '）');
    }

    /**
     * 计数桩适配器：除 placeOrder/精度方法外全部抛异常（证明不该被走到）。
     */
    private function stubAdapter(?string $reuseClientOrderId = null): object
    {
        return new class($reuseClientOrderId) implements ExchangeAdapter {
            public int $calls = 0;

            public ?string $lastClientOrderId = null;

            public function __construct(?string $reuseClientOrderId)
            {
                $this->lastClientOrderId = $reuseClientOrderId;
            }

            public function placeOrder(
                string $symbol,
                string $side,
                string $type,
                string $quantity,
                ?string $price = null,
                ?string $stopPrice = null,
                array $opts = [],
            ): ExchangeOrder {
                $this->calls++;
                $this->lastClientOrderId = (string) ($opts['clientOrderId'] ?? '');

                return new ExchangeOrder(
                    exchangeOrderId: 'stub-1',
                    clientOrderId: $this->lastClientOrderId,
                    symbol: $symbol,
                    side: $side,
                    type: $type,
                    quantity: $quantity,
                    price: $price,
                    stopPrice: $stopPrice,
                    leverage: null,
                    reduceOnly: false,
                    status: 'filled',
                    filledQty: $quantity,
                    avgFillPrice: $price,
                    fee: '0',
                    placedAt: time(),
                    filledAt: time(),
                );
            }

            /** @param array<string,mixed> $_opts */
            public function symbolFilters(string $symbol): array
            {
                return ['step_size' => '0.00001000', 'min_notional' => '1', 'tick_size' => '0.01000000'];
            }

            public function alignToStep(string $value, string $step): string
            {
                if (bccomp($step, '0', 12) !== 1) {
                    return $value;
                }
                $floored = bcadd(bcdiv($value, $step, 12), '0', 0);

                return bcmul($floored, $step, 8);
            }

            public function getTicker(string $symbol): ExchangeTicker
            {
                throw new \RuntimeException('unexpected getTicker in billing verify');
            }

            /** @return ExchangeKline[] */
            public function getKline(string $symbol, string $interval, int $limit = 500): array
            {
                throw new \RuntimeException('unexpected getKline in billing verify');
            }

            public function getBalance(): ExchangeBalance
            {
                throw new \RuntimeException('unexpected getBalance in billing verify');
            }

            public function getPosition(string $symbol): ?ExchangePosition
            {
                throw new \RuntimeException('unexpected getPosition in billing verify');
            }

            public function cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool
            {
                throw new \RuntimeException('unexpected cancelOrder in billing verify');
            }

            public function getOrder(string $symbol, string $orderId): ExchangeOrder
            {
                throw new \RuntimeException('unexpected getOrder in billing verify');
            }

            public function subscribeKlineStream(string $symbol, string $interval, callable $onBar, ?callable $onTick = null): string
            {
                throw new \RuntimeException('unexpected subscribeKlineStream in billing verify');
            }

            public function unsubscribeStream(string $subscriptionId): void
            {
                throw new \RuntimeException('unexpected unsubscribeStream in billing verify');
            }

            public function subscribeLiquidationStream(callable $onLiquidation): string
            {
                throw new \RuntimeException('unexpected subscribeLiquidationStream in billing verify');
            }
        };
    }

    // ------------------------------------------------------------------
    // 7. 资源数/渠道门（含端到端 poc:signal-setup）
    // ------------------------------------------------------------------

    private function checkResourceGates(QuotaGuard $guard): void
    {
        $this->info(PHP_EOL . '---- 7. 资源数/渠道门 ----');
        $t = $this->tenantA;

        try {
            $guard->assertChannels($t, ['mail']);
            $channelThrew = false;
        } catch (EntitlementException) {
            $channelThrew = true;
        }
        $this->check($channelThrew, 'free 订阅 mail 渠道被 QuotaGuard 拒绝');
        $guard->assertChannels($t, ['log']); // 不抛即通过
        $this->check(true, 'log 渠道放行');

        $threw = false;
        try {
            $guard->assertResourceCount($t, 'max_signal_subscriptions', 1);
        } catch (QuotaExceededException) {
            $threw = true;
        }
        $this->check($threw, '当前=1 上限=1 时新建被拒');

        // 端到端：mail 渠道整体失败且无落行（临时置空 AI key 保持离线，不触发 DeepSeek 探测）
        $savedAiKey = config('ai.providers.deepseek.api_key');
        config(['ai.providers.deepseek.api_key' => '']);
        $before = (int) DB::table('signal_subscriptions')->where('tenant_id', $t)->count();
        $exitMail = $this->call('poc:signal-setup', [
            '--tenant' => $t, '--symbol' => 'ETHUSDT', '--channel' => 'mail',
        ]);
        $afterMail = (int) DB::table('signal_subscriptions')->where('tenant_id', $t)->count();
        $this->check($exitMail === self::FAILURE && $afterMail === $before, 'poc:signal-setup mail 端到端被拒且无落行');

        // log 渠道端到端放行（写 1 行，供 check9 隔离断言）
        $exitLog = $this->call('poc:signal-setup', [
            '--tenant' => $t, '--symbol' => 'ETHUSDT', '--channel' => 'log',
        ]);
        config(['ai.providers.deepseek.api_key' => $savedAiKey]);
        $created = DB::table('signal_subscriptions')
            ->where('tenant_id', $t)->where('name', 'poc-signal-ETHUSDT')->where('active', 1)->exists();
        $this->check($exitLog === self::SUCCESS && $created, 'poc:signal-setup log 端到端放行并落 active 订阅');
    }

    // ------------------------------------------------------------------
    // 8. DEK 轮换
    // ------------------------------------------------------------------

    private function checkDekRotation(TenantKeyResolver $resolver, AesGcmCrypt $crypt): void
    {
        $this->info(PHP_EOL . '---- 8. DEK 轮换 ----');

        $dekAOld = $resolver->tenantDek($this->tenantA);
        $dekBOld = $resolver->tenantDek($this->tenantB);
        $storedOld = (string) DB::table('tenants')->where('id', $this->tenantA)->value('exchange_dek');
        $accountRow = DB::table('exchange_accounts')->where('id', $this->accountA)->first();
        $plainKeyOld = $crypt->decrypt((string) $accountRow->encrypted_api_key, $dekAOld);
        $plainSecretOld = $crypt->decrypt((string) $accountRow->encrypted_api_secret, $dekAOld);
        $oldKeyCipher = (string) $accountRow->encrypted_api_key;
        $oldSecretCipher = (string) $accountRow->encrypted_api_secret;

        $fingerprint = $resolver->rotateDek($this->tenantA);

        $storedNew = (string) DB::table('tenants')->where('id', $this->tenantA)->value('exchange_dek');
        $this->check($storedNew !== '' && $storedNew !== $storedOld, 'tenants.exchange_dek 已更新（指纹=' . substr($fingerprint, 0, 10) . '）');

        $dekANew = $resolver->tenantDek($this->tenantA);
        $this->check($dekANew !== $dekAOld, '新 DEK 与旧 DEK 不同');

        $accountRow = DB::table('exchange_accounts')->where('id', $this->accountA)->first();
        $plainKeyNew = $crypt->decrypt((string) $accountRow->encrypted_api_key, $dekANew);
        $plainSecretNew = $crypt->decrypt((string) $accountRow->encrypted_api_secret, $dekANew);
        $this->check($plainKeyNew === 'poc-key-A' && $plainKeyNew === $plainKeyOld, '新 DEK 解密 api_key 与轮换前一致');
        $this->check($plainSecretNew === 'poc-secret-A' && $plainSecretNew === $plainSecretOld, '新 DEK 解密 secret 与轮换前一致');
        $this->check(
            (string) $accountRow->encrypted_api_key !== $oldKeyCipher
            && (string) $accountRow->encrypted_api_secret !== $oldSecretCipher,
            '旧密文已从库中消失（两列均已重加密）',
        );
        $leftover = DB::table('exchange_accounts')
            ->where('encrypted_api_key', $oldKeyCipher)
            ->orWhere('encrypted_api_secret', $oldSecretCipher)
            ->count();
        $this->check($leftover === 0, '全表扫描旧密文残留=0');

        // B 租户不受影响
        $dekBAfter = $resolver->tenantDek($this->tenantB);
        $bRow = DB::table('exchange_accounts')->where('id', $this->accountB)->first();
        $bPlain = $crypt->decrypt((string) $bRow->encrypted_api_key, $dekBAfter);
        $this->check($dekBAfter === $dekBOld && $bPlain === 'poc-key-B', '租户 B 的 DEK/凭据不受租户 A 轮换影响');
    }

    // ------------------------------------------------------------------
    // 9. 租户隔离
    // ------------------------------------------------------------------

    private function checkTenantIsolation(): void
    {
        $this->info(PHP_EOL . '---- 9. TenantScope 隔离 ----');

        TenantContext::initialize($this->tenantA);
        $aSeesV2 = MeterEvent::query()->where('ref_id', 'v2-1')->exists();
        $aSubCount = MeterEvent::query()->count();
        $aSignalSubs = SignalSubscription::query()->count();
        $bSubVisibleFromA = Subscription::query()->where('tenant_id', $this->tenantB)->count();
        TenantContext::clear();

        TenantContext::initialize($this->tenantB);
        $bSeesV2 = MeterEvent::query()->where('ref_id', 'v2-1')->exists();
        $bSubCount = MeterEvent::query()->count();
        $aSubVisibleFromB = Subscription::query()->where('tenant_id', $this->tenantA)->count();
        $aSignalSubsFromB = SignalSubscription::query()->where('tenant_id', $this->tenantA)->count();
        TenantContext::clear();

        $this->check($aSeesV2 && ! $bSeesV2, 'A 的 v2-1 计量行仅 A 可见，B 不可见');
        $this->check($aSubCount > 0 && $bSubCount >= 0, "双方各自只见己方 meter（A={$aSubCount}）");
        $this->check($bSubVisibleFromA === 0 && $aSubVisibleFromB === 0, 'Subscription 双向跨租户查询为 0 行');
        $this->check($aSignalSubs >= 1 && $aSignalSubsFromB === 0, 'SignalSubscription A 自检可见、B 查 A 为 0');

        // 平台级（无上下文）：能看到两家
        $platformA = TenantContext::runWithoutTenant(fn () => MeterEvent::query()->where('tenant_id', $this->tenantA)->count());
        $platformB = TenantContext::runWithoutTenant(fn () => MeterEvent::query()->where('tenant_id', $this->tenantB)->count());
        $this->check($platformA === $aSubCount && $platformA > 0, '平台上下文可显式按租户聚合（A=' . $platformA . '）');
        $this->check($platformB === $bSubCount, '平台上下文可显式按租户聚合（B=' . $platformB . '）');
    }

    // ------------------------------------------------------------------
    // 清理
    // ------------------------------------------------------------------

    private function flushMeterKeys(int $tenantId): void
    {
        $redis = Redis::connection('default');
        foreach (['ai_call', 'order_placed'] as $event) {
            $day = now()->format('Ymd');
            $redis->del("tenant:{$tenantId}:meter:{$event}:{$day}");
            $redis->del("tenant:{$tenantId}:dek:rotation");
        }
    }

    private function deleteTenantCascade(int $tenantId): void
    {
        $signalIds = DB::table('signals')->where('tenant_id', $tenantId)->pluck('id')->all();
        if ($signalIds !== []) {
            DB::table('signal_notifications')->whereIn('signal_id', $signalIds)->delete();
        }
        DB::table('signals')->where('tenant_id', $tenantId)->delete();
        DB::table('strategy_orders')->where('tenant_id', $tenantId)->delete();
        DB::table('strategy_instances')->where('tenant_id', $tenantId)->delete();
        DB::table('signal_subscriptions')->where('tenant_id', $tenantId)->delete();
        DB::table('meter_events')->where('tenant_id', $tenantId)->delete();
        DB::table('subscriptions')->where('tenant_id', $tenantId)->delete();
        DB::table('exchange_accounts')->where('tenant_id', $tenantId)->delete();
        DB::table('tenants')->where('id', $tenantId)->delete();
        $this->flushMeterKeys($tenantId);
    }

    private function cleanupFixtures(): void
    {
        TenantContext::clear();
        if (isset($this->tenantA)) {
            $this->deleteTenantCascade($this->tenantA);
        }
        if (isset($this->tenantB)) {
            $this->deleteTenantCascade($this->tenantB);
        }
    }
}
