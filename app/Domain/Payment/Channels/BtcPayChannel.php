<?php

declare(strict_types=1);

namespace App\Domain\Payment\Channels;

use App\Domain\Payment\Contracts\PaymentChannel;
use App\Domain\Payment\Dto\PaymentInvoiceData;
use App\Domain\Payment\Dto\PaymentInvoiceRequest;
use App\Domain\Payment\Dto\WebhookEvent;
use App\Domain\Payment\Exceptions\PaymentChannelException;
use Illuminate\Support\Facades\Http;

/**
 * BTCPay Server 自托管通道（真实轨道，spec 007 / payment-channel-contract.md §5）。
 *
 * 验签为纯函数（HMAC-SHA256 + hash_equals，对原始字节），可离线单测；
 * 建单走 Http facade（超时 10s），真实链路验收待节点（docs/p9-btcpay-poc-plan.md 阶段 A/B/C）。
 */
final class BtcPayChannel implements PaymentChannel
{
    private readonly ?string $baseUrl;

    private readonly ?string $storeId;

    private readonly ?string $apiKey;

    private readonly ?string $webhookSecret;

    private readonly int $timeout;

    public function __construct()
    {
        $config = (array) config('payment.channels.btcpay', []);
        $this->baseUrl = isset($config['base_url']) ? (string) $config['base_url'] : null;
        $this->storeId = isset($config['store_id']) ? (string) $config['store_id'] : null;
        $this->apiKey = isset($config['api_key']) ? (string) $config['api_key'] : null;
        $this->webhookSecret = isset($config['webhook_secret']) ? (string) $config['webhook_secret'] : null;
        $this->timeout = (int) ($config['timeout'] ?? 10);
    }

    public function code(): string
    {
        return 'btcpay';
    }

    public function createInvoice(PaymentInvoiceRequest $request): PaymentInvoiceData
    {
        if (! $this->baseUrl || ! $this->storeId || ! $this->apiKey) {
            throw new PaymentChannelException('BTCPay 通道配置缺失（base_url / store_id / api_key）');
        }

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->post(rtrim($this->baseUrl, '/')."/api/v1/stores/{$this->storeId}/invoices", [
                'amount' => $request->amount,
                'currency' => $request->symbol,
                'metadata' => [
                    'ref' => $request->ref,
                    'tenant_id' => $request->tenantId,
                    'plan_code' => $request->planCode,
                ],
            ]);

        if (! $response->successful()) {
            throw new PaymentChannelException(
                "BTCPay 建单失败：HTTP {$response->status()} ".$response->body()
            );
        }

        $data = (array) $response->json();
        $id = $data['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new PaymentChannelException('BTCPay 建单应答非法：缺少 id');
        }

        $checkoutLink = isset($data['checkoutLink']) ? (string) $data['checkoutLink'] : null;

        return new PaymentInvoiceData(
            channelInvoiceId: $id,
            payAddress: (string) ($data['address'] ?? $checkoutLink ?? ''),
            payUri: $checkoutLink,
            amount: isset($data['amount']) ? (string) $data['amount'] : $request->amount,
            expiresAt: isset($data['expirationTime']) ? (string) $data['expirationTime'] : null,
            raw: $data,
        );
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $signature = $this->headerValue($headers, 'btcpay-sig');
        if ($signature === null || ! $this->webhookSecret) {
            return false;
        }
        // 头形如 sha256=<hex>，前缀非法直接拒绝
        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);

        return hash_equals($expected, substr($signature, 7));
    }

    public function parseWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $data = json_decode($rawBody, true);
        if (! is_array($data) || ! isset($data['type']) || ! is_string($data['type']) || $data['type'] === '') {
            throw new PaymentChannelException('BTCPay webhook 载荷不可解析：缺少 type 字段');
        }

        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];

        $paidAmount = null;
        if (isset($data['paidAmount'])) {
            $paidAmount = (string) $data['paidAmount'];
        } elseif (isset($data['payment']) && is_array($data['payment']) && isset($data['payment']['value'])) {
            $paidAmount = (string) $data['payment']['value'];
        }

        return new WebhookEvent(
            eventType: $data['type'],
            ref: isset($metadata['ref']) ? (string) $metadata['ref'] : null,
            channelInvoiceId: isset($data['invoiceId']) ? (string) $data['invoiceId'] : (isset($data['id']) ? (string) $data['id'] : null),
            paidAmount: $paidAmount,
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
