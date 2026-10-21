<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SignalSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'symbols' => $this->symbols,
            'intervals' => $this->intervals,
            'ai_prompter' => $this->ai_prompter,
            'ai_model' => $this->ai_model,
            'rule_fallback_enabled' => (bool) $this->rule_fallback_enabled,
            'channels' => $this->channels,
            'active' => (bool) $this->active,
            'auto_execute' => (bool) $this->auto_execute,
            'execution_instance_id' => $this->execution_instance_id !== null
                ? (int) $this->execution_instance_id
                : null,
            'execution_notional' => $this->execution_notional,
            'execution_min_confidence' => (int) $this->execution_min_confidence,
            'execution_daily_limit' => (int) $this->execution_daily_limit,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
