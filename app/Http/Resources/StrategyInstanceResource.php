<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StrategyInstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'strategy_id' => $this->strategy_id,
            'strategy' => $this->whenLoaded('strategy', fn () => [
                'id' => $this->strategy->id,
                'code' => $this->strategy->code,
                'name' => $this->strategy->name,
                'category' => $this->strategy->category,
            ]),
            'exchange_account_id' => $this->exchange_account_id,
            'exchange_account' => $this->whenLoaded('exchangeAccount', fn () => [
                'id' => $this->exchangeAccount->id,
                'label' => $this->exchangeAccount->label,
                'market' => $this->exchangeAccount->market,
            ]),
            'symbol' => $this->symbol,
            'interval' => $this->interval,
            'params' => $this->params,
            'allocated_capital' => $this->allocated_capital,
            'leverage' => $this->leverage,
            'status' => $this->status,
            'breaker_state' => $this->breaker_state,
            'realized_pnl' => $this->realized_pnl,
            'unrealized_pnl' => $this->unrealized_pnl,
            'max_drawdown_pct' => $this->max_drawdown_pct,
            'consecutive_losses' => $this->consecutive_losses,
            'started_at' => $this->started_at?->toIso8601String(),
            'stopped_at' => $this->stopped_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
