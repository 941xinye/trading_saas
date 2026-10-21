<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 参数寻优任务资源（P11）。
 *
 * report 含 best/top（walk_forward 另含 fold_details），trials 明细走独立端点分页。
 */
class ParameterOptimizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'strategy_code' => $this->strategy_code,
            'symbol' => $this->symbol,
            'interval' => $this->interval,
            'from_utc' => $this->from_utc?->toIso8601String(),
            'to_utc' => $this->to_utc?->toIso8601String(),
            'mode' => $this->mode,
            'folds' => $this->folds,
            'objective' => $this->objective,
            'grid' => $this->grid,
            'base_params' => $this->base_params ?? [],
            'top_n' => $this->top_n,
            'assumptions' => $this->assumptions,
            'status' => $this->status->value,
            'progress_pct' => $this->progress_pct,
            'best_params' => $this->best_params,
            'best_score' => $this->best_score,
            'report' => $this->report,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'applied_instance_id' => $this->applied_instance_id,
            'applied_at' => $this->applied_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
