<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 平台级策略目录（spot_grid/futures_grid/spot_dca/custom_dsl）。
 */
class Strategy extends Model
{
    protected $fillable = [
        'code',
        'name',
        'category',
        'risk_profile',
        'default_params',
        'dsl_schema',
        'enabled',
    ];

    protected $casts = [
        'default_params' => 'array',
        'dsl_schema' => 'array',
        'enabled' => 'boolean',
    ];

    /**
     * 该策略在各租户下的运行实例。
     */
    public function instances(): HasMany
    {
        return $this->hasMany(StrategyInstance::class);
    }
}
