<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Payment\Contracts\PaymentChannel;
use App\Domain\Payment\Contracts\PaymentChannelRegistry;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * 支付通道管理器（镜像 ExchangeManager 范式：按 code 路由、未知 code 抛异常）。
 *
 * 读 config('payment.channels.{code}.driver') → 容器解析；缺 code / driver 未实现
 * PaymentChannel → 抛异常，绝不降级（constitution V）。
 */
final class PaymentChannelManager implements PaymentChannelRegistry
{
    public function __construct(private readonly Container $app) {}

    public function for(string $code): PaymentChannel
    {
        $driver = config("payment.channels.{$code}.driver");
        if (! is_string($driver) || $driver === '') {
            throw new RuntimeException("未知支付通道或未配置 driver：{$code}");
        }
        if (! class_exists($driver)) {
            throw new RuntimeException("支付通道 driver 类不存在：{$driver}");
        }

        $channel = $this->app->make($driver);
        if (! $channel instanceof PaymentChannel) {
            throw new RuntimeException("支付通道 driver 未实现 PaymentChannel 接口：{$driver}");
        }

        return $channel;
    }

    /** @return string[] */
    public function codes(): array
    {
        return array_keys((array) config('payment.channels', []));
    }

    public function default(): PaymentChannel
    {
        return $this->for((string) config('payment.default_channel'));
    }
}
