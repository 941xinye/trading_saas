<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Domain\Payment\Channels\FakePaymentChannel;
use App\Domain\Payment\Contracts\PaymentChannelRegistry;
use App\Domain\Payment\Exceptions\PaymentChannelException;
use RuntimeException;
use Tests\TestCase;

/**
 * 支付通道注册表配置红线（FR-001 / constitution V：未知通道抛异常，绝不降级）。
 */
class PaymentChannelConfigTest extends TestCase
{
    public function test_unknown_channel_code_throws(): void
    {
        $registry = app(PaymentChannelRegistry::class);

        $this->expectException(RuntimeException::class);
        $registry->for('no_such_channel');
    }

    public function test_channel_without_driver_config_throws(): void
    {
        config()->set('payment.channels.bogus', ['name' => '缺 driver']);

        $registry = app(PaymentChannelRegistry::class);

        $this->expectException(RuntimeException::class);
        $registry->for('bogus');
    }

    public function test_fake_channel_throws_outside_allowed_env(): void
    {
        // 当前 APP_ENV=testing；把白名单改成 production 后解析 fake 必须抛异常（R-004 红线）
        config()->set('payment.channels.fake.allowed_env', ['production']);

        $registry = app(PaymentChannelRegistry::class);

        $this->expectException(PaymentChannelException::class);
        $registry->for('fake');
    }

    public function test_fake_channel_resolves_in_testing_env(): void
    {
        $channel = app(PaymentChannelRegistry::class)->for('fake');

        static::assertInstanceOf(FakePaymentChannel::class, $channel);
        static::assertSame('fake', $channel->code());
    }

    public function test_codes_and_default_channel(): void
    {
        $registry = app(PaymentChannelRegistry::class);

        static::assertContains('fake', $registry->codes());
        static::assertContains('btcpay', $registry->codes());
        static::assertSame('fake', $registry->default()->code());
    }
}
