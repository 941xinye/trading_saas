<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * 支付单（一笔套餐购买/续费收款单据，ref 为幂等键）。
 *
 * 金额列 decimal(20,8) + decimal:8 cast（读出 string，直接喂 bccomp/bcsub，浮点禁入）。
 */
class PaymentInvoice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'ref',
        'channel_code',
        'channel_invoice_id',
        'plan_code',
        'currency',
        'chain',
        'amount_usdt',
        'paid_amount_usdt',
        'pay_address',
        'pay_uri',
        'status',
        'expires_at',
        'confirmed_at',
        'meta',
    ];

    protected $casts = [
        'amount_usdt' => 'decimal:8',
        'paid_amount_usdt' => 'decimal:8',
        'meta' => 'array',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];
}
