<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 账实对账报告（P5.5 步骤 1）。
 *
 * 每租户每实例每周期至多一行（unique 锚点）；报告为审计事实，
 * 业务代码不得据其自动改账。
 */
class ReconciliationReport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'strategy_instance_id',
        'exchange_account_id',
        'status',
        'diff_json',
        'period_key',
        'checked_at',
    ];

    protected $casts = [
        'diff_json' => 'array',
        'checked_at' => 'datetime',
    ];

    public function strategyInstance(): BelongsTo
    {
        return $this->belongsTo(StrategyInstance::class);
    }

    public function exchangeAccount(): BelongsTo
    {
        return $this->belongsTo(ExchangeAccount::class);
    }
}
