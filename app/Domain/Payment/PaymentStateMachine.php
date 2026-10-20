<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Payment\Dto\PaymentStatus;
use App\Domain\Payment\Dto\PaymentTransition;

/**
 * 支付单状态机（webhook-contract.md §3 迁移表的纯逻辑表达）。
 *
 * 输入 = 当前状态 + 通道事件类型 + 实付金额（bcmath 字符串）；
 * 输出 = 目标迁移（含条件更新源状态白名单、是否接线激活），null = 丢弃
 * （未知事件 / 乱序 / 终态重复投递 → 上层落 idempotent_skipped，状态只进不退）。
 *
 * 金额比较一律 bccomp（scale=8），浮点禁入（FR-011 / FR-013）。
 */
final class PaymentStateMachine
{
    /**
     * @param  string[]  $settledEvents  足额确认事件（→ confirmed / 过期后 → manual）
     * @param  string[]  $processingEvents  支付中事件（→ processing / partial）
     * @param  string[]  $expiredEvents  过期事件（→ expired）
     */
    public function __construct(
        private readonly array $settledEvents,
        private readonly array $processingEvents,
        private readonly array $expiredEvents,
    ) {}

    /**
     * 按通道配置构造（config('payment.channels.{code}') 三键事件分类）。
     */
    public static function forChannel(string $channelCode): self
    {
        return new self(
            (array) config("payment.channels.{$channelCode}.settled_events", []),
            (array) config("payment.channels.{$channelCode}.processing_events", []),
            (array) config("payment.channels.{$channelCode}.expired_events", []),
        );
    }

    /**
     * 决策目标迁移；null = 丢弃（不落任何状态变更）。
     */
    public function decide(
        PaymentStatus $current,
        string $eventType,
        ?string $paidAmount,
        string $amount,
    ): ?PaymentTransition {
        // 终态只进不退：唯一例外 = expired 后足额到账 → manual（不自动激活，FR-012）
        if ($current->isTerminal()) {
            if ($current === PaymentStatus::Expired
                && in_array($eventType, $this->settledEvents, true)
                && $paidAmount !== null
                && bccomp($paidAmount, $amount, 8) >= 0
            ) {
                return new PaymentTransition(PaymentStatus::Manual, [PaymentStatus::Expired], false);
            }

            return null;
        }

        if (in_array($eventType, $this->expiredEvents, true)) {
            return new PaymentTransition(
                PaymentStatus::Expired,
                [PaymentStatus::Pending, PaymentStatus::Processing, PaymentStatus::Partial],
                false,
            );
        }

        if (in_array($eventType, $this->settledEvents, true)) {
            // 防御：settle 事件但实付不足额 → 按部分支付处理，不接线（FR-013）
            if ($this->isPartialPayment($paidAmount, $amount)) {
                return new PaymentTransition(
                    PaymentStatus::Partial,
                    [PaymentStatus::Pending, PaymentStatus::Processing],
                    false,
                );
            }

            // 足额 / 超额 → confirmed + 接线（超额不退款不记账，FR-006）
            return new PaymentTransition(
                PaymentStatus::Confirmed,
                [PaymentStatus::Pending, PaymentStatus::Processing, PaymentStatus::Partial],
                true,
            );
        }

        if (in_array($eventType, $this->processingEvents, true)) {
            if ($this->isPartialPayment($paidAmount, $amount)) {
                return new PaymentTransition(
                    PaymentStatus::Partial,
                    [PaymentStatus::Pending, PaymentStatus::Processing],
                    false,
                );
            }

            return new PaymentTransition(PaymentStatus::Processing, [PaymentStatus::Pending], false);
        }

        // 未知事件类型：确认收到、无需处理（如 InvoiceCreated 等噪声事件）
        return null;
    }

    /**
     * 0 < paid < amount（bcmath 判定，任一空值/零额均非部分支付）。
     */
    private function isPartialPayment(?string $paidAmount, string $amount): bool
    {
        return $paidAmount !== null
            && bccomp($paidAmount, '0', 8) > 0
            && bccomp($paidAmount, $amount, 8) < 0;
    }
}
