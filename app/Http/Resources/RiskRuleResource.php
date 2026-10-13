<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiskRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'strategy_instance_id' => $this->strategy_instance_id,
            'rule_type' => $this->rule_type,
            'threshold_value' => $this->threshold_value,
            'threshold_unit' => $this->threshold_unit,
            'cooldown_minutes' => $this->cooldown_minutes,
            'auto_recover' => (bool) $this->auto_recover,
            'enabled' => (bool) $this->enabled,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
