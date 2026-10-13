<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 平台级租户模型（不带 BelongsToTenant trait）。
 *
 * exchange_dek 存放用 KEK 加密过的 DEK（密钥层级详见架构方案 §2）。
 */
class Tenant extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'exchange_dek',
        'status',
        'plan_code',
        'trial_ends_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
    ];

    /**
     * 该租户下的所有用户（含租户管理员/trader/viewer）。
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * 该租户的订阅记录（计费）。
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
