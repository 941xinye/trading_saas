<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Backtest\Models\ParameterOptimizationApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 寻优结果应用记录（含优化任务摘要，供实例反查）。
 *
 * @mixin ParameterOptimizationApplication
 */
class ParameterOptimizationApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'optimization_id' => $this->optimization_id,
            'strategy_instance_id' => $this->strategy_instance_id,
            'rank' => $this->rank,
            'params_before' => $this->params_before,
            'params_after' => $this->params_after,
            'applied_by' => $this->applied_by,
            'applied_at' => $this->created_at?->toIso8601String(),
            'rolled_back_at' => $this->rolled_back_at?->toIso8601String(),
            'optimization' => $this->whenLoaded('optimization', fn (): array => [
                'id' => $this->optimization->id,
                'strategy_code' => $this->optimization->strategy_code,
                'symbol' => $this->optimization->symbol,
                'interval' => $this->optimization->interval,
                'mode' => $this->optimization->mode,
                'objective' => $this->optimization->objective,
                'best_score' => $this->optimization->best_score,
                'applied_at' => $this->optimization->applied_at?->toIso8601String(),
            ]),
            'instance' => $this->whenLoaded('instance', fn (): array => [
                'id' => $this->instance->id,
                'symbol' => $this->instance->symbol,
                'interval' => $this->instance->interval,
                'status' => $this->instance->status,
            ]),
        ];
    }
}
