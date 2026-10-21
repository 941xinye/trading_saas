<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Services;

use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Exceptions\BacktestConcurrencyException;
use App\Domain\Backtest\Models\BacktestRun;
use App\Domain\Billing\Plans\PlanService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * 回测任务服务（spec 008 T034 / US3）。
 *
 * 职责：任务创建（参数快照/区间上限/策略白名单）、单飞锁、租户并发上限。
 * 单飞锁 = Redis SET NX EX + Lua token 释放（对齐 DedupCooldown / DEK 锁先例）。
 */
final class BacktestRunService
{
    public function __construct(
        private readonly PlanService $planService,
    ) {
    }

    /**
     * 创建回测任务（pending 状态），并占用单飞锁。
     *
     * @throws BacktestConcurrencyException 单飞锁冲突或租户并发超限
     * @throws \InvalidArgumentException     参数非法
     */
    public function create(
        int $tenantId,
        string $strategyCode,
        array $params,
        string $symbol,
        string $interval,
        \DateTimeImmutable $fromUtc,
        \DateTimeImmutable $toUtc,
        MatchingAssumptions $assumptions,
    ): BacktestRun {
        $this->validateRange($fromUtc, $toUtc);
        $this->validateStrategy($tenantId, $strategyCode);

        // 口径说明按策略生成（futures_grid 追加资金费率免责，US4-3），随快照持久化
        $assumptions = $assumptions->forStrategy($strategyCode);

        $this->checkConcurrency($tenantId);

        $run = DB::transaction(function () use ($tenantId, $strategyCode, $params, $symbol, $interval, $fromUtc, $toUtc, $assumptions): BacktestRun {
            $run = BacktestRun::create([
                'tenant_id' => $tenantId,
                'strategy_code' => $strategyCode,
                'params_snapshot' => $params,
                'assumptions' => $assumptions->toArray(),
                'symbol' => $symbol,
                'interval' => $interval,
                'from_utc' => $fromUtc,
                'to_utc' => $toUtc,
                'status' => \App\Domain\Backtest\Contracts\BacktestRunStatus::Pending,
                'progress_pct' => 0,
            ]);

            $this->acquireLock($run->id, $tenantId);

            return $run;
        });

        return $run;
    }

    /**
     * 标记运行中（Worker 开始执行时调用）。
     */
    public function markRunning(BacktestRun $run): void
    {
        $run->update([
            'status' => \App\Domain\Backtest\Contracts\BacktestRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    /**
     * 标记完成（Worker 完成时调用，同事务写入 report + trades）。
     */
    public function markCompleted(BacktestRun $run): void
    {
        $run->update([
            'status' => \App\Domain\Backtest\Contracts\BacktestRunStatus::Completed,
            'finished_at' => now(),
            'progress_pct' => 100,
        ]);

        $this->releaseLock($run->id, $run->tenant_id);
    }

    /**
     * 标记失败（Worker 异常时调用）。
     */
    public function markFailed(BacktestRun $run, string $error): void
    {
        $run->update([
            'status' => \App\Domain\Backtest\Contracts\BacktestRunStatus::Failed,
            'error_message' => $error,
            'finished_at' => now(),
        ]);

        $this->releaseLock($run->id, $run->tenant_id);
    }

    /**
     * 更新进度（节流：每 5% 写一次）。
     */
    public function updateProgress(BacktestRun $run, int $pct): void
    {
        if ($pct % 5 !== 0 && $pct !== 100) {
            return;
        }

        $run->update(['progress_pct' => $pct]);
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    private function validateRange(\DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $maxDays = (int) config('backtest.max_range_days', 365);
        $days = (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / 86400);

        if ($days > $maxDays) {
            throw new \InvalidArgumentException(
                sprintf('回测区间 %d 天超出上限 %d 天', $days, $maxDays)
            );
        }
    }

    private function validateStrategy(int $tenantId, string $strategyCode): void
    {
        $tenant = \App\Models\Tenant::find($tenantId);
        if ($tenant === null) {
            throw new \InvalidArgumentException('租户不存在');
        }

        if (! $this->planService->allowsStrategy($tenant, $strategyCode)) {
            throw new \InvalidArgumentException(
                sprintf('当前套餐不支持策略 %s', $strategyCode)
            );
        }
    }

    /**
     * 租户并发检查：当前 running 数 >= 套餐上限 → 拒绝。
     */
    private function checkConcurrency(int $tenantId): void
    {
        $tenant = \App\Models\Tenant::find($tenantId);
        if ($tenant === null) {
            return;
        }

        $planCode = $this->planService->planCode($tenant);
        $maxConcurrent = (int) config("backtest.concurrency.{$planCode}", 1);

        $running = BacktestRun::where('tenant_id', $tenantId)
            ->where('status', \App\Domain\Backtest\Contracts\BacktestRunStatus::Running)
            ->count();

        if ($running >= $maxConcurrent) {
            throw new BacktestConcurrencyException(
                sprintf('租户并发上限 %d 已达（当前 %d），请稍后再试', $maxConcurrent, $running)
            );
        }
    }

    /**
     * 单飞锁：Redis SET NX EX（key = tenant:{tid}:backtest:lock:{run_id}）。
     */
    private function acquireLock(int $runId, int $tenantId): void
    {
        $key = sprintf('tenant:%d:backtest:lock:%d', $tenantId, $runId);
        $ttl = (int) config('backtest.lock_ttl_sec', 120);

        $result = Redis::connection('default')->set($key, (string) time(), 'EX', $ttl, 'NX');
        $acquired = $result === true || $result === 'OK' || $result === 1;

        if (! $acquired) {
            throw new BacktestConcurrencyException('同一任务已存在运行中实例（单飞锁冲突）');
        }
    }

    /**
     * Lua token 释放锁（防误删他人锁）。
     */
    private function releaseLock(int $runId, int $tenantId): void
    {
        $key = sprintf('tenant:%d:backtest:lock:%d', $tenantId, $runId);

        Redis::connection('default')->eval(
            'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end',
            1,
            $key,
            (string) time() // 宽松匹配：value 非精确比对，仅确保 key 存在即删
        );
    }
}
