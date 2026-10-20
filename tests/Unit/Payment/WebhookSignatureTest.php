<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Domain\Payment\Channels\BtcPayChannel;
use Tests\TestCase;

/**
 * BTCPay webhook 验签（FR-004）：HMAC-SHA256 + hash_equals，对原始字节验签。
 *
 * 正例 1 + 负例 4（篡改 body / 错密钥 / 缺头 / 前缀非法），纯计算无需联网。
 */
class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'btcpay-test-secret';

    private BtcPayChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('payment.channels.btcpay.webhook_secret', self::SECRET);
        $this->channel = new BtcPayChannel;
    }

    public function test_valid_signature_passes(): void
    {
        $body = '{"type":"InvoiceSettled","invoiceId":"inv_1","metadata":{"ref":"pi_01jabc"}}';
        $sig = 'sha256='.hash_hmac('sha256', $body, self::SECRET);

        static::assertTrue($this->channel->verifyWebhook($body, ['BTCPay-Sig' => $sig]));
    }

    public function test_tampered_body_rejected(): void
    {
        $body = '{"type":"InvoiceSettled"}';
        $sig = 'sha256='.hash_hmac('sha256', $body, self::SECRET);

        static::assertFalse($this->channel->verifyWebhook($body.'tampered', ['BTCPay-Sig' => $sig]));
    }

    public function test_wrong_secret_rejected(): void
    {
        $body = '{"type":"InvoiceSettled"}';
        $sig = 'sha256='.hash_hmac('sha256', $body, 'another-secret');

        static::assertFalse($this->channel->verifyWebhook($body, ['BTCPay-Sig' => $sig]));
    }

    public function test_missing_header_rejected(): void
    {
        static::assertFalse($this->channel->verifyWebhook('{"type":"InvoiceSettled"}', []));
    }

    public function test_illegal_prefix_rejected(): void
    {
        $body = '{"type":"InvoiceSettled"}';
        $sig = 'sha1='.hash_hmac('sha256', $body, self::SECRET);

        static::assertFalse($this->channel->verifyWebhook($body, ['BTCPay-Sig' => $sig]));
    }
}
