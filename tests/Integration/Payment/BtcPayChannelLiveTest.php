<?php

declare(strict_types=1);

namespace Tests\Integration\Payment;

use App\Domain\Payment\Channels\BtcPayChannel;
use App\Domain\Payment\Contracts\PaymentChannelRegistry;
use App\Domain\Payment\Dto\PaymentInvoiceRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

/**
 * 真实 BTCPay 端到端用例（T060 / docs/p9-btcpay-poc-plan.md 阶段 A/B 前置探针）。
 *
 * 可用性守卫：BTCPAY_BASE_URL/STORE_ID/API_KEY 未配置或服务不可达 → markTestSkipped
 * （沿用 ChecksRedisAvailability 同口径：skipped 不计失败，基线不变红，坑 #23）。
 *
 * 节点就绪后本用例真实执行：建单走真实 API（B1 前置），验签用配置的
 * BTCPAY_WEBHOOK_SECRET 对原始字节做 HMAC-SHA256（纯函数）。
 *
 * 注意：本用例只建「探针小额单」并在结束后尽力标记 Invalid 清理；
 * 真实 USDT 支付到 Settled 的 B1/B2/B3 验收仍按 PoC 方案人工执行。
 */
class BtcPayChannelLiveTest extends TestCase
{
    private ?string $createdInvoiceId = null;

    protected function tearDown(): void
    {
        // 尽力清理：探针单标记 Invalid（节点未就绪/清理失败均忽略）
        try {
            if ($this->createdInvoiceId !== null) {
                $baseUrl = rtrim((string) config('payment.channels.btcpay.base_url'), '/');
                $storeId = (string) config('payment.channels.btcpay.store_id');
                Http::withToken((string) config('payment.channels.btcpay.api_key'))
                    ->timeout(5)
                    ->post("{$baseUrl}/api/v1/stores/{$storeId}/invoices/{$this->createdInvoiceId}/status", [
                        'status' => 'Invalid',
                    ]);
            }
        } catch (Throwable) {
            // 清理失败不影响测试结论
        }

        parent::tearDown();
    }

    public function test_btcpay_create_invoice_round_trip(): void
    {
        $this->requireBtcPay();

        $channel = app(PaymentChannelRegistry::class)->for('btcpay');
        static::assertInstanceOf(BtcPayChannel::class, $channel);

        $data = $channel->createInvoice(new PaymentInvoiceRequest(
            tenantId: 0,
            ref: 'pi_liveprobe000000000000aa',
            planCode: 'pro',
            symbol: 'USDT',
            chain: 'TRC20',
            amount: '0.01000000',
            description: 'live probe invoice (auto-marked invalid)',
            ttlMinutes: 5,
        ));

        $this->createdInvoiceId = $data->channelInvoiceId;

        static::assertNotSame('', $data->channelInvoiceId, 'BTCPay 应答缺少 invoice id');
        static::assertNotSame('', (string) ($data->payUri ?? ''), 'BTCPay 应答缺少 checkoutLink');
        // 金额保真（bcmath 字符串口径，FR-013）
        static::assertSame(0, bccomp($data->amount, '0.01000000', 8), 'BTCPay 应答金额与请求不一致');
        // metadata 回读（B3 依赖 ref 反定位）
        $meta = (array) ($data->raw['metadata'] ?? []);
        static::assertSame('pi_liveprobe000000000000aa', (string) ($meta['ref'] ?? ''), 'metadata.ref 未随单回读');
    }

    public function test_btcpay_webhook_signature_with_configured_secret(): void
    {
        $this->requireBtcPay();

        $secret = (string) config('payment.channels.btcpay.webhook_secret');
        static::assertNotSame('', $secret, 'BTCPAY_WEBHOOK_SECRET 未配置（真实投递验签将无法通过）');

        $channel = app(PaymentChannelRegistry::class)->for('btcpay');
        $rawBody = (string) json_encode([
            'type' => 'InvoiceSettled',
            'invoiceId' => 'probe',
            'metadata' => ['ref' => 'pi_liveprobe000000000000aa'],
        ]);

        $good = 'sha256='.hash_hmac('sha256', $rawBody, $secret);
        static::assertTrue($channel->verifyWebhook($rawBody, ['BTCPay-Sig' => $good]), '配置密钥下合法签名应通过');
        static::assertFalse($channel->verifyWebhook($rawBody, ['BTCPay-Sig' => 'sha256='.str_repeat('0', 64)]), '伪造签名应拒绝');
        static::assertFalse($channel->verifyWebhook($rawBody.'tampered', ['BTCPay-Sig' => $good]), '篡改载荷应拒绝');
        static::assertFalse($channel->verifyWebhook($rawBody, []), '缺签名头应拒绝');

        $event = $channel->parseWebhook($rawBody, ['BTCPay-Sig' => $good]);
        static::assertSame('InvoiceSettled', $event->eventType);
        static::assertSame('pi_liveprobe000000000000aa', $event->ref);
    }

    /**
     * 可用性守卫：配置缺失或 HTTP 不可达 → skip（绝不让基线变红）。
     */
    private function requireBtcPay(): void
    {
        $baseUrl = rtrim((string) config('payment.channels.btcpay.base_url'), '/');
        $storeId = (string) config('payment.channels.btcpay.store_id');
        $apiKey = (string) config('payment.channels.btcpay.api_key');

        if ($baseUrl === '' || $storeId === '' || $apiKey === '') {
            $this->markTestSkipped('BTCPay 未配置（BTCPAY_BASE_URL / BTCPAY_STORE_ID / BTCPAY_API_KEY），真实链路待节点（docs/p9-btcpay-poc-plan.md）。');
        }

        try {
            // 任意 HTTP 应答（含 401/404）即视为服务在线；仅连接异常判不可达
            Http::withToken($apiKey)->timeout(5)->get("{$baseUrl}/api/v1/health");
        } catch (Throwable $e) {
            $this->markTestSkipped(sprintf('BTCPay 服务不可达，跳过真实链路网关用例（%s）', $e->getMessage()));
        }
    }
}
