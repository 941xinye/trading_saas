<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * dev-only：构造带签名的 fake 通道 webhook 并投递至本平台端点（等价真实通道投递）。
 *
 * 红线：非 local/testing 环境禁用（防「假通道收单」资损，与 FakePaymentChannel 同口径）。
 * 签名 = HMAC-SHA256(rawBody, config('payment.channels.fake.secret'))，头 X-Fake-Sig。
 *
 * 用法：
 *   php artisan billing:fake-webhook --ref=pi_xxx --event=fake.settled --paid=49.00000000
 */
class BillingFakeWebhookCommand extends Command
{
    protected $signature = 'billing:fake-webhook
        {--ref= : 支付单 ref（pi_ 开头）}
        {--event=fake.settled : 事件类型（fake.settled / fake.processing / fake.expired）}
        {--paid= : 实付金额（bcmath 字符串，缺省按足额由平台单金额兜底）}';

    protected $description = 'Deliver a signed fake-channel webhook to the local platform endpoint (dev only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('billing:fake-webhook 仅允许在 local/testing 环境使用。');

            return self::FAILURE;
        }

        $ref = (string) $this->option('ref');
        if ($ref === '') {
            $this->error('--ref 必填。');

            return self::FAILURE;
        }

        $event = (string) $this->option('event');
        $payload = (string) json_encode([
            'event' => $event,
            'ref' => $ref,
            'invoice_id' => 'fake_'.$ref,
            'paid_amount' => $this->option('paid') !== null ? (string) $this->option('paid') : null,
            'currency' => 'USDT',
        ]);

        $secret = (string) config('payment.channels.fake.secret', 'fake-webhook-secret');
        $signature = hash_hmac('sha256', $payload, $secret);

        $url = url('/api/v1/billing/webhook/fake');
        $response = Http::withHeaders(['X-Fake-Sig' => $signature])
            ->withBody($payload, 'application/json')
            ->post($url);

        $this->line(sprintf('POST %s → %d', $url, $response->status()));
        $this->line($response->body());

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
