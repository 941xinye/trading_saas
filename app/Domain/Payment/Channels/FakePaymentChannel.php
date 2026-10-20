<?php

declare(strict_types=1);

namespace App\Domain\Payment\Channels;

use App\Domain\Payment\Contracts\PaymentChannel;
use App\Domain\Payment\Dto\PaymentInvoiceData;
use App\Domain\Payment\Dto\PaymentInvoiceRequest;
use App\Domain\Payment\Dto\WebhookEvent;
use App\Domain\Payment\Exceptions\PaymentChannelException;

/**
 * Fake 支付通道（离线轨道，spec 007 / payment-channel-contract.md §4）。
 *
 * 红线（research R-004）：仅允许在 local/testing 环境使用；其它环境被解析即抛异常，
 * 防「假通道收单」资损事故。生产 MUST 显式 PAYMENT_CHANNEL=btcpay。
 */
final class FakePaymentChannel implements PaymentChannel
{
    private readonly string $secret;

    public function __construct()
    {
        $allowed = (array) config('payment.channels.fake.allowed_env', ['local', 'testing']);
        if (! app()->environment($allowed)) {
            throw new PaymentChannelException(
                'fake 支付通道仅允许在 ['.implode(',', $allowed).'] 环境使用，当前环境：'.app()->environment()
            );
        }
        $this->secret = (string) config('payment.channels.fake.secret', 'fake-webhook-secret');
    }

    public function code(): string
    {
        return 'fake';
    }

    public function createInvoice(PaymentInvoiceRequest $request): PaymentInvoiceData
    {
        // 确定性假地址（34 位 T 开头，镜像 TRC20 形态）
        $address = 'TFAKE'.strtoupper(substr(md5($request->ref), 0, 29));

        return new PaymentInvoiceData(
            channelInvoiceId: 'fake_'.$request->ref,
            payAddress: $address,
            payUri: 'tron:'.$address.'?amount='.$request->amount.'&token=USDT',
            amount: $request->amount,
            expiresAt: now('UTC')->addMinutes($request->ttlMinutes)->toIso8601String(),
            raw: ['ref' => $request->ref, 'driver' => 'fake'],
        );
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $signature = $this->headerValue($headers, 'x-fake-sig');
        if ($signature === null || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $this->secret), $signature);
    }

    public function parseWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $data = json_decode($rawBody, true);
        if (! is_array($data) || ! isset($data['event']) || ! is_string($data['event']) || $data['event'] === '') {
            throw new PaymentChannelException('fake webhook 载荷不可解析：缺少 event 字段');
        }

        return new WebhookEvent(
            eventType: $data['event'],
            ref: isset($data['ref']) ? (string) $data['ref'] : null,
            channelInvoiceId: isset($data['invoice_id']) ? (string) $data['invoice_id'] : null,
            paidAmount: isset($data['paid_amount']) ? (string) $data['paid_amount'] : null,
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            raw: $data,
        );
    }

    /** @param array<string, mixed> $headers */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return null;
    }
}
