<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 租户级交易所账户（API Key 用 KEK 加密的 DEK 二次加密存储）。
 */
class ExchangeAccount extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'exchange_id',
        'label',
        'market',
        'sub_account',
        'encrypted_api_key',
        'encrypted_api_secret',
        'passphrase_encrypted',
        'dek_version',
        'permissions',
        'status',
        'last_synced_at',
    ];

    protected $casts = [
        'permissions' => 'array',
        'last_synced_at' => 'datetime',
        'dek_version' => 'integer',
    ];

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }

    public function strategyInstances(): HasMany
    {
        return $this->hasMany(StrategyInstance::class);
    }

    public function balanceSnapshots(): HasMany
    {
        return $this->hasMany(BalanceSnapshot::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }
}
