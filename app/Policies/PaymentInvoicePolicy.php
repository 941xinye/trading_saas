<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentInvoice;
use App\Models\User;

/**
 * 支付单授权（spec 007 / FR-009 / FR-014）。
 *
 * 只读：仅定义 viewAny / view；create / update / delete / refund 等方法刻意缺省，
 * Gate 对未定义能力一律拒绝 —— 支付单不可在界面被创建/修改/删除，
 * 退款人工线下处理（FR-014），平台侧不提供入口。
 */
class PaymentInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null;
    }

    public function view(User $user, PaymentInvoice $paymentInvoice): bool
    {
        return $user->tenant_id !== null;
    }
}
