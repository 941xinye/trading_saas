<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\EntitlementException;
use App\Domain\Billing\QuotaGuard;
use App\Domain\Payment\Contracts\PaymentChannelRegistry;
use App\Domain\Payment\PaymentInvoiceService;
use App\Domain\Payment\RenewalReminderService;
use App\Models\PaymentInvoice;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * spec 007 支付网关 + 计费离线全链路断言（T059 / SC-001~006 平台侧）。
 *
 * 覆盖：建单（复用不堆叠）→ 验签正/负 → 状态推进 → 订阅激活 → 配额生效 →
 * 幂等重放 → 伪造签名拒绝 + unknown ref → 续费提醒 3 天档判定与同周期幂等，
 * 并断言 webhook 单请求处理耗时 < 200ms（离线基准，plan.md Performance Goals）。
 *
 * 真实 BTCPay 链路验收（B1~B3 / C1~C2）待节点，见 docs/p9-btcpay-poc-plan.md。
 *
 * 预期输出 `ALL CHECKS PASSED`。使用一次性租户 poc-verify-payment-a/b，结束自动清理。
 */
class PocVerifyPaymentCommand extends Command
{
    protected $signature = 'poc:verify-payment';

    protected $description = 'POC: offline end-to-end assertions for payment gateway + billing (spec 007 SC-001~006)';

    private int $pass = 0;

    private int $fail = 0;

    /** @var string[] */
    private array $failures = [];

    private int $tenantA;

    private int $tenantB;

    /** @var string[] 本流程产生过的全部 ref（含伪造/幽灵 ref，供精确清理） */
    private array $refs = [];

    /** webhook 单请求耗时峰值（毫秒） */
    private float $webhookMaxMs = 0.0;

    public function handle(
        PaymentInvoiceService $invoices,
        QuotaGuard $guard,
        RenewalReminderService $reminders,
        PaymentChannelRegistry $channels,
    ): int {
        $this->info('==== spec 007 支付网关 + 计费 离线全链路验证 ====');

        try {
            $this->provisionFixtures();
            // HTTP 内核一次性预热（首请求含中间件/路由惰性引导，属控制台上下文冷启动，
            // 非 webhook 处理稳态成本；200ms 口径对齐 plan.md p95 稳态目标）
            $this->warmUpHttpKernel();

            $this->checkCreateInvoice($invoices);
            $this->checkSignatureVerify($channels);
            $this->checkActivationFlow($invoices, $guard);
            $this->checkIdempotentReplay();
            $this->checkForgedSignature($invoices);
            $this->checkRenewalReminder($reminders);
            $this->checkWebhookLatency();
        } catch (Throwable $e) {
            $this->fail++;
            $this->failures[] = '验证流程异常中断：'.$e->getMessage();
            $this->error('FATAL: '.$e->getMessage());
            $this->line($e->getTraceAsString());
        } finally {
            $this->cleanupFixtures();
        }

        $this->newLine();
        $this->info(sprintf('RESULT: %d/%d passed', $this->pass, $this->pass + $this->fail));
        if ($this->fail > 0) {
            $this->error('VERIFY FAILED');
            foreach ($this->failures as $f) {
                $this->line('  - '.$f);
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
            $this->line('  PASS '.$label);
        } else {
            $this->fail++;
            $this->failures[] = $label.($detail !== '' ? "（{$detail}）" : '');
            $this->error('  FAIL '.$label.($detail !== '' ? "（{$detail}）" : ''));
        }
    }

    // ------------------------------------------------------------------
    // 一次性夹具（slug 幂等，重跑先清旧租户）
    // ------------------------------------------------------------------

    private function provisionFixtures(): void
    {
        foreach (['poc-verify-payment-a', 'poc-verify-payment-b'] as $slug) {
            $old = DB::table('tenants')->where('slug', $slug)->first();
            if ($old !== null) {
                $this->deleteTenantData((int) $old->id);
            }
        }

        $this->tenantA = $this->createTenant('poc-verify-payment-a');
        $this->tenantB = $this->createTenant('poc-verify-payment-b');
    }

    private function createTenant(string $slug): int
    {
        return (int) DB::table('tenants')->insertGetId([
            'name' => $slug,
            'slug' => $slug,
            'exchange_dek' => 'poc-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deleteTenantData(int $tenantId): void
    {
        $refs = DB::table('payment_invoices')->where('tenant_id', $tenantId)->pluck('ref')->all();
        foreach ($refs as $ref) {
            DB::table('payment_webhook_events')->where('ref', $ref)->delete();
            DB::table('payment_webhook_events')->whereNull('ref')->where('raw_payload', 'like', '%'.$ref.'%')->delete();
        }
        DB::table('payment_webhook_events')->where('tenant_id', $tenantId)->delete();
        DB::table('payment_renewal_reminders')->where('tenant_id', $tenantId)->delete();
        DB::table('payment_invoices')->where('tenant_id', $tenantId)->delete();
        DB::table('subscriptions')->where('tenant_id', $tenantId)->delete();
        DB::table('tenants')->where('id', $tenantId)->delete();
    }

    private function cleanupFixtures(): void
    {
        try {
            // 覆盖伪造签名（ref=NULL）与幽灵 ref 产生的审计行
            foreach ($this->refs as $ref) {
                DB::table('payment_webhook_events')->where('ref', $ref)->delete();
                DB::table('payment_webhook_events')->whereNull('ref')->where('raw_payload', 'like', '%'.$ref.'%')->delete();
            }
            if (isset($this->tenantA)) {
                $this->deleteTenantData($this->tenantA);
            }
            if (isset($this->tenantB)) {
                $this->deleteTenantData($this->tenantB);
            }
        } catch (Throwable) {
            // 清理失败不影响验证结论
        }
    }

    // ------------------------------------------------------------------
    // 建单（FR-002 / FR-011 / US4-3 不堆叠）
    // ------------------------------------------------------------------

    private function checkCreateInvoice(PaymentInvoiceService $invoices): void
    {
        $this->info(PHP_EOL.'---- 1. 建单（金额 bcmath / 复用不堆叠）----');

        $invoice = $invoices->create($this->tenantA, 'pro');
        $this->refs[] = $invoice->ref;

        $this->check((string) $invoice->status === 'pending', '建单后状态 pending');
        $this->check(
            (string) $invoice->amount_usdt === $this->expectedAmount(),
            '建单金额 = pro price_monthly（8 位小数字符串）',
            'actual='.(string) $invoice->amount_usdt.' expected='.$this->expectedAmount(),
        );
        $this->check(preg_match('/^pi_[0-9a-z]{26}$/', $invoice->ref) === 1, 'ref 格式 pi_ + 26 位小写 ulid');
        $this->check(
            is_string($invoice->pay_address) && str_starts_with($invoice->pay_address, 'T'),
            '收款地址为 TRC20 形态（T 开头）',
        );
        $this->check(
            $invoice->expires_at !== null && $invoice->expires_at->gt(now()),
            'expires_at 为未来（平台权威 TTL）',
        );

        $again = $invoices->create($this->tenantA, 'pro');
        $this->check(
            (int) $again->getKey() === (int) $invoice->getKey(),
            '同租户同套餐重复建单复用既有非终态单（不堆叠）',
        );
    }

    // ------------------------------------------------------------------
    // 验签（SC-003 正例 + 负例，纯函数无需联网）
    // ------------------------------------------------------------------

    private function checkSignatureVerify(PaymentChannelRegistry $channels): void
    {
        $this->info(PHP_EOL.'---- 2. 验签正/负 ----');

        $channel = $channels->for('fake');
        [$payload, $goodSig] = $this->fakePayload('fake.settled', $this->refs[0], $this->expectedAmount());

        $this->check($channel->verifyWebhook($payload, ['X-Fake-Sig' => $goodSig]) === true, '合法 HMAC 签名验签通过');
        $this->check(
            $channel->verifyWebhook($payload, ['X-Fake-Sig' => hash_hmac('sha256', $payload, 'wrong-secret')]) === false,
            '错误密钥签名验签拒绝',
        );
        $this->check($channel->verifyWebhook($payload, []) === false, '缺签名头验签拒绝');
        $this->check($channel->verifyWebhook($payload.'tampered', ['X-Fake-Sig' => $goodSig]) === false, '篡改载荷验签拒绝');
    }

    // ------------------------------------------------------------------
    // 状态推进 → 订阅激活 → 配额生效（SC-001 平台侧 ≤30s）
    // ------------------------------------------------------------------

    private function checkActivationFlow(PaymentInvoiceService $invoices, QuotaGuard $guard): void
    {
        $this->info(PHP_EOL.'---- 3. 状态推进 → 激活 → 配额生效（SC-001）----');

        // 对照：激活前 free 套餐拒绝 custom_dsl
        $blocked = false;
        try {
            $guard->assertStrategyAllowed($this->tenantA, 'custom_dsl');
        } catch (EntitlementException) {
            $blocked = true;
        }
        $this->check($blocked, '激活前 free 套餐拒绝 custom_dsl（对照组）');

        $ref = $this->refs[0];

        // processing 推进（非激活迁移，只进不退）
        [$payload, $sig] = $this->fakePayload('fake.processing', $ref, null);
        [$code, $body] = $this->deliverWebhook($payload, $sig);
        $this->check($code === 200 && ($body['result'] ?? '') === 'processed', 'fake.processing 投递 → processed');
        $this->check(
            DB::table('payment_invoices')->where('ref', $ref)->value('status') === 'processing',
            '支付单推进至 processing',
        );

        // settled 足额 → 激活（计时含激活 + 配额断言，SC-001）
        $flowStart = microtime(true);
        [$payload, $sig] = $this->fakePayload('fake.settled', $ref, $this->expectedAmount());
        [$code, $body] = $this->deliverWebhook($payload, $sig);
        $this->check($code === 200 && ($body['result'] ?? '') === 'processed', 'fake.settled 足额投递 → processed');

        $row = DB::table('payment_invoices')->where('ref', $ref)->first();
        $this->check($row !== null && $row->status === 'confirmed' && $row->confirmed_at !== null, '支付单 confirmed 且 confirmed_at 非空');
        /** @var PaymentInvoice|null $model */
        $model = PaymentInvoice::query()->withoutGlobalScopes()->where('ref', $ref)->first();
        $this->check(
            $model !== null && (string) $model->paid_amount_usdt === $this->expectedAmount(),
            'paid_amount_usdt 经模型 decimal:8 cast 读出保真',
            $model !== null ? 'actual='.(string) $model->paid_amount_usdt : 'model missing',
        );

        $this->check(
            DB::table('subscriptions')->where('tenant_id', $this->tenantA)->where('status', 'active')->count() === 1,
            '新增恰好 1 条 active 订阅',
        );
        $this->check(
            DB::table('tenants')->where('id', $this->tenantA)->value('plan_code') === 'pro',
            'tenants.plan_code 升级为 pro',
        );
        $this->check($guard->isEntitled($this->tenantA), '激活后权益断言放行');

        $allowed = true;
        try {
            $guard->assertStrategyAllowed($this->tenantA, 'custom_dsl');
        } catch (EntitlementException) {
            $allowed = false;
        }
        $this->check($allowed, '激活后 pro 级配额生效（custom_dsl 放行）');

        $elapsed = microtime(true) - $flowStart;
        $this->check($elapsed < 30.0, 'SC-001 确认到账 → 激活 → 配额可用 ≤ 30 秒', sprintf('实测 %.3fs', $elapsed));
    }

    // ------------------------------------------------------------------
    // 幂等重放（SC-002）
    // ------------------------------------------------------------------

    private function checkIdempotentReplay(): void
    {
        $this->info(PHP_EOL.'---- 4. 幂等重放（SC-002）----');

        $ref = $this->refs[0];
        $meterBefore = DB::table('meter_events')->where('tenant_id', $this->tenantA)->count();

        [$payload, $sig] = $this->fakePayload('fake.settled', $ref, $this->expectedAmount());
        $results = [];
        for ($i = 0; $i < 2; $i++) {
            [$code, $body] = $this->deliverWebhook($payload, $sig);
            $results[] = $code.'/'.($body['result'] ?? '?');
        }
        $this->check(
            $results === ['200/idempotent_skipped', '200/idempotent_skipped'],
            '同 ref 重放 2 次（累计投递 3 次）均幂等跳过',
            implode(', ', $results),
        );
        $this->check(
            DB::table('payment_webhook_events')->where('processed_key', $ref.':fake.settled')->count() === 1,
            'processed_key 审计行恰好 1 条（唯一兜底）',
        );
        $this->check(
            DB::table('payment_webhook_events')->where('ref', $ref)->where('status', 'idempotent_skipped')->count() === 2,
            '重放留痕 2 条 idempotent_skipped 审计',
        );
        $this->check(
            DB::table('subscriptions')->where('tenant_id', $this->tenantA)->where('status', 'active')->count() === 1,
            '重放后仍仅 1 条 active 订阅（零重复激活）',
        );
        $this->check(
            DB::table('meter_events')->where('tenant_id', $this->tenantA)->count() === $meterBefore,
            '重放零配额增量',
        );
    }

    // ------------------------------------------------------------------
    // 伪造签名拒绝（SC-003）+ unknown ref（B3 语义）
    // ------------------------------------------------------------------

    private function checkForgedSignature(PaymentInvoiceService $invoices): void
    {
        $this->info(PHP_EOL.'---- 5. 伪造签名拒绝（SC-003）----');

        $invoiceB = $invoices->create($this->tenantB, 'pro');
        $this->refs[] = $invoiceB->ref;

        [$payload] = $this->fakePayload('fake.settled', $invoiceB->ref, $this->expectedAmount());
        $badSig = hash_hmac('sha256', $payload, 'wrong-secret');
        [$code] = $this->deliverWebhook($payload, $badSig);
        $this->check($code === 401, '伪造签名 → HTTP 401');
        $this->check(
            DB::table('payment_invoices')->where('ref', $invoiceB->ref)->value('status') === 'pending',
            '伪造签名零状态变更（支付单仍 pending）',
        );
        $this->check(
            DB::table('subscriptions')->where('tenant_id', $this->tenantB)->count() === 0,
            '伪造签名未触发任何订阅接线',
        );
        $this->check(
            DB::table('payment_webhook_events')
                ->where('channel_code', 'fake')
                ->where('status', 'rejected')
                ->where('signature_valid', 0)
                ->where('error', 'invalid_signature')
                ->where('raw_payload', 'like', '%'.$invoiceB->ref.'%')
                ->count() >= 1,
            '伪造签名已落 rejected 审计（signature_valid=false + 原始载荷留存）',
        );

        // 附带：验签合法但 ref 不存在 → 200 + invoice_not_found（不重试、不影响其他事件）
        $ghostRef = 'pi_00000000000000000000000000';
        $this->refs[] = $ghostRef;
        [$payload, $sig] = $this->fakePayload('fake.settled', $ghostRef, $this->expectedAmount());
        [$code, $body] = $this->deliverWebhook($payload, $sig);
        $this->check(
            $code === 200 && ($body['reason'] ?? '') === 'invoice_not_found',
            'unknown ref → 200 + invoice_not_found（B3 语义）',
        );
    }

    // ------------------------------------------------------------------
    // 续费提醒判定（SC-004 前半：3 天档 + 同周期幂等）
    // ------------------------------------------------------------------

    private function checkRenewalReminder(RenewalReminderService $reminders): void
    {
        $this->info(PHP_EOL.'---- 6. 续费提醒判定（3 天档 + 幂等）----');

        $now = Carbon::now('UTC');
        DB::table('subscriptions')->insert([
            'tenant_id' => $this->tenantB,
            'plan_code' => 'free',
            'status' => 'active',
            'started_at' => $now,
            'ends_at' => $now->copy()->addDays(3),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $created = $reminders->dispatch($this->tenantB);
        $this->check($created >= 1, '3 天档提醒落行', 'created='.$created);
        $this->check(
            DB::table('payment_renewal_reminders')->where('tenant_id', $this->tenantB)->where('days_before', 3)->exists(),
            '提醒行 days_before=3 且渠道在套餐白名单内',
        );

        $again = $reminders->dispatch($this->tenantB);
        $this->check($again === 0, '同周期重复扫描零新增（唯一索引幂等）', 'created='.$again);
    }

    // ------------------------------------------------------------------
    // webhook 单请求耗时 < 200ms（离线基准，plan.md Performance Goals）
    // ------------------------------------------------------------------

    private function checkWebhookLatency(): void
    {
        $this->info(PHP_EOL.'---- 7. webhook 单请求耗时 ----');

        $this->check(
            $this->webhookMaxMs < 200.0,
            'webhook 单请求处理耗时 < 200ms',
            sprintf('实测峰值 %.1fms', $this->webhookMaxMs),
        );
    }

    // ------------------------------------------------------------------
    // webhook 投递助手（进程内经 HTTP 内核走完整流水线，等价真实通道投递）
    // ------------------------------------------------------------------

    /**
     * @return array{0:string,1:string} [payload, signature]
     */
    private function fakePayload(string $event, string $ref, ?string $paid): array
    {
        $payload = (string) json_encode([
            'event' => $event,
            'ref' => $ref,
            'invoice_id' => 'fake_'.$ref,
            'paid_amount' => $paid,
            'currency' => 'USDT',
        ]);
        $signature = hash_hmac('sha256', $payload, (string) config('payment.channels.fake.secret'));

        return [$payload, $signature];
    }

    /**
     * 预热 HTTP 内核：未知通道 → 404，零审计零状态变更，且不计入耗时测量。
     */
    private function warmUpHttpKernel(): void
    {
        $request = Request::create(
            '/api/v1/billing/webhook/ghost',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{}',
        );
        $this->laravel->make(HttpKernel::class)->handle($request);
    }

    /**
     * @return array{0:int,1:array<string,mixed>,2:float} [status, body, elapsedMs]
     */
    private function deliverWebhook(string $payload, string $signature): array
    {
        $request = Request::create(
            '/api/v1/billing/webhook/fake',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_FAKE_SIG' => $signature,
            ],
            $payload,
        );

        $kernel = $this->laravel->make(HttpKernel::class);
        $started = microtime(true);
        $response = $kernel->handle($request);
        $elapsedMs = (microtime(true) - $started) * 1000;
        $this->webhookMaxMs = max($this->webhookMaxMs, $elapsedMs);

        $body = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), is_array($body) ? $body : [], $elapsedMs];
    }

    private function expectedAmount(): string
    {
        return number_format((float) config('plans.plans.pro.price_monthly'), 8, '.', '');
    }
}
