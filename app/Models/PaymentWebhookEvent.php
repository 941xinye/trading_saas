<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 支付 webhook 接收审计（每次投递留痕）。
 *
 * 不挂 BelongsToTenant：webhook 回调无租户上下文，tenant_id 由服务端按 ref 反查后显式写入
 * （与 TwoPhaseMeter 同思路）。
 */
class PaymentWebhookEvent extends Model
{
    protected $fillable = [
        'tenant_id',
        'payment_invoice_id',
        'channel_code',
        'ref',
        'event_type',
        'signature_valid',
        'status',
        'processed_key',
        'raw_payload',
        'error',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'signature_valid' => 'boolean',
        'raw_payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
