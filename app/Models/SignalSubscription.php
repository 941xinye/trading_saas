<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 租户级 AI 信号订阅（symbols/intervals/channels 多对多用 json 数组）。
 */
class SignalSubscription extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'symbols',
        'intervals',
        'ai_prompter',
        'ai_model',
        'rule_fallback_enabled',
        'channels',
        'active',
    ];

    protected $casts = [
        'symbols' => 'array',
        'intervals' => 'array',
        'channels' => 'array',
        'rule_fallback_enabled' => 'boolean',
        'active' => 'boolean',
    ];

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }
}
