<?php

declare(strict_types=1);

namespace App\Domain\Risk\Position;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 仓位风控事件审计仓库（position_risk_events 表）。
 *
 * best-effort 写入：审计失败不阻塞风控动作本身。
 */
class PositionRiskEventRepository
{
    /**
     * 写入风控动作审计行。
     *
     * @param  array<string,mixed>  $data  字段映射
     */
    public function create(array $data): ?int
    {
        try {
            return (int) DB::table('position_risk_events')->insertGetId([
                'tenant_id' => $data['tenant_id'],
                'strategy_instance_id' => $data['instance_id'],
                'symbol' => $data['symbol'],
                'position_side' => $data['position_side'],
                'action_type' => $data['action_type'],
                'trigger_reason' => $data['trigger_reason'],
                'margin_ratio' => $data['margin_ratio'],
                'mark_price' => $data['mark_price'],
                'liquidation_price' => $data['liquidation_price'] ?? null,
                'position_before' => json_encode($data['position_before'], JSON_UNESCAPED_UNICODE),
                'position_after' => isset($data['position_after'])
                    ? json_encode($data['position_after'], JSON_UNESCAPED_UNICODE) : null,
                'order_id' => $data['order_id'] ?? null,
                'status' => $data['status'] ?? 'pending',
                'error_message' => $data['error_message'] ?? null,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('position_risk_events insert failed', [
                'instance' => $data['instance_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 更新审计行状态（动作执行结果）。
     */
    public function updateStatus(int $eventId, string $status, ?string $errorMessage = null): void
    {
        try {
            DB::table('position_risk_events')->where('id', $eventId)->update([
                'status' => $status,
                'error_message' => $errorMessage,
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('position_risk_events update failed', [
                'id' => $eventId, 'error' => $e->getMessage(),
            ]);
        }
    }
}
