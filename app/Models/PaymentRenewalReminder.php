<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * 续费提醒发送事实（唯一索引承担每周期每档每渠道的幂等）。
 */
class PaymentRenewalReminder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'subscription_ends_at',
        'days_before',
        'plan_code',
        'channel',
        'status',
        'provider_message_id',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'subscription_ends_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
