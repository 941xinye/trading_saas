<?php

declare(strict_types=1);

namespace App\Domain\Payment\Contracts;

/**
 * 支付通道注册表（spec 007 / payment-channel-contract.md §3）。
 */
interface PaymentChannelRegistry
{
    /**
     * @throws \RuntimeException 未知 channel_code 或 driver 未配置（绝不降级，constitution V）
     */
    public function for(string $code): PaymentChannel;

    /** @return string[] 已注册通道 code */
    public function codes(): array;

    /** config('payment.default_channel') 对应通道。 */
    public function default(): PaymentChannel;
}
