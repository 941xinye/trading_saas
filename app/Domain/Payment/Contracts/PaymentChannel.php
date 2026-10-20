<?php

declare(strict_types=1);

namespace App\Domain\Payment\Contracts;

use App\Domain\Payment\Dto\PaymentInvoiceData;
use App\Domain\Payment\Dto\PaymentInvoiceRequest;
use App\Domain\Payment\Dto\WebhookEvent;
use App\Domain\Payment\Exceptions\PaymentChannelException;

/**
 * 支付通道契约（spec 007 / payment-channel-contract.md §1）。
 *
 * 通道只做「建单 / 验签 / 解析」三件纯函数式动作；状态机与订阅接线全在平台侧。
 * 新增通道 = 实现本接口 + config/payment.php 注册一行（FR-001 纯增量）。
 */
interface PaymentChannel
{
    /** 通道标识，与 payment_invoices.channel_code / config('payment.channels') 键一致。 */
    public function code(): string;

    /**
     * 创建通道侧发票。
     *
     * @throws PaymentChannelException 通道不可用/配置缺失/应答非法（不产生半状态支付单）
     */
    public function createInvoice(PaymentInvoiceRequest $request): PaymentInvoiceData;

    /**
     * webhook 验签（纯计算，无 IO）。
     * 失败 MUST 返回 false（调用方据此 401 + 落审计），不得抛异常吞掉判定。
     */
    public function verifyWebhook(string $rawBody, array $headers): bool;

    /**
     * 规范化 webhook 事件。MUST NOT 在此做状态迁移或写库（纯解析）。
     *
     * @throws PaymentChannelException 载荷无法解析为已知事件
     */
    public function parseWebhook(string $rawBody, array $headers): WebhookEvent;
}
