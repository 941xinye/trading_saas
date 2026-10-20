<?php

declare(strict_types=1);

namespace App\Domain\Payment\Dto;

/**
 * 状态迁移决策（PaymentStateMachine 纯逻辑产物）。
 *
 * fromStates 为条件更新的源状态白名单（webhook-contract.md §3 迁移表）：
 * 当前状态不在其中即乱序/重复投递，由 decide() 直接返回 null 丢弃。
 */
final readonly class PaymentTransition
{
    /**
     * @param  PaymentStatus[]  $fromStates  条件更新 WHERE status IN (...) 白名单
     * @param  bool  $activate  是否接线订阅激活（仅足额 confirmed 为 true）
     */
    public function __construct(
        public PaymentStatus $target,
        public array $fromStates,
        public bool $activate,
    ) {}
}
