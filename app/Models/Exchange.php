<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 平台级交易所目录（binance/okx/bybit/bitget 等）。
 */
class Exchange extends Model
{
    protected $fillable = [
        'code',
        'name',
        'base_url',
        'ws_url',
        'markets',
        'weight_limits',
        'status',
    ];

    protected $casts = [
        'markets' => 'array',
        'weight_limits' => 'array',
    ];

    /**
     * 该交易所的健康检查历史。
     */
    public function healthChecks(): HasMany
    {
        return $this->hasMany(ExchangeHealthCheck::class);
    }

    /**
     * 各租户挂在该交易所的账户。
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(ExchangeAccount::class);
    }
}
