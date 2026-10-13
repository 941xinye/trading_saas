<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SignalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'subscription_id' => $this->subscription_id,
            'symbol' => $this->symbol,
            'interval' => $this->interval,
            'direction' => $this->direction,
            'entry_price' => $this->entry_price,
            'exit_price' => $this->exit_price,
            'stop_loss' => $this->stop_loss,
            'confidence' => $this->confidence,
            'source' => $this->source, // ai | rule_fallback
            'rationale' => $this->rationale,
            'raw_payload' => $this->raw_payload,
            'triggered_at' => $this->triggered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
