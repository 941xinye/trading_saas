<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 参数寻优单次试跑资源（P11，append-only）。
 */
class ParameterOptimizationTrialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'optimization_id' => $this->optimization_id,
            'fold_index' => $this->fold_index,
            'params' => $this->params,
            'in_sample' => (bool) $this->in_sample,
            'status' => $this->status,
            'score' => $this->score,
            'metrics' => $this->metrics,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
