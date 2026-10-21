<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Jobs;

use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Dto\OptimizationRequest;
use App\Domain\Backtest\Models\ParameterOptimization;
use App\Domain\Backtest\Services\OptimizationService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 参数寻优执行 Job（backtest 队列，tries=1）。
 *
 * 流程：按 optimization_id 取记录 → 还原请求快照 → TenantContext::initialize → execute。
 * 失败：execute 已把记录标 failed，这里只记日志，不重试。
 */
class RunOptimizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $optimizationId,
    ) {
        $this->onQueue('backtest');
    }

    public function handle(OptimizationService $service): void
    {
        $record = ParameterOptimization::query()->find($this->optimizationId);

        if ($record === null || $record->status->isTerminal()) {
            return;
        }

        // Job 边界恢复租户上下文（对齐 RunBacktestJob 先例）
        TenantContext::initialize((int) $record->tenant_id);

        try {
            $service->execute($record, $this->requestFromRecord($record));
        } catch (Throwable $e) {
            Log::error('参数寻优执行失败', [
                'optimization_id' => $record->id,
                'tenant_id' => $record->tenant_id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            TenantContext::clear();
        }
    }

    /**
     * 记录快照 → 请求 DTO（async 执行不依赖调用方上下文）。
     */
    private function requestFromRecord(ParameterOptimization $record): OptimizationRequest
    {
        return new OptimizationRequest(
            tenantId: (int) $record->tenant_id,
            strategyCode: (string) $record->strategy_code,
            symbol: (string) $record->symbol,
            interval: (string) $record->interval,
            fromUtc: $record->from_utc->toDateTimeImmutable(),
            toUtc: $record->to_utc->toDateTimeImmutable(),
            grid: (array) ($record->grid ?? []),
            mode: (string) $record->mode,
            folds: (int) $record->folds,
            objective: (string) $record->objective,
            assumptions: MatchingAssumptions::fromArray((array) ($record->assumptions ?? [])),
            baseParams: (array) ($record->base_params ?? []),
            topN: max(1, (int) ($record->top_n ?? config('optimization.top_n', 5))),
        );
    }
}
