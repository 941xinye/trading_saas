<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use App\Domain\Notification\DedupCooldown;
use App\Domain\Signal\Contracts\Signal;
use App\Domain\Signal\Contracts\SignalDispatcher as SignalDispatcherContract;
use App\Jobs\DispatchNotificationJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 信号分发实现。
 *
 * 职责（架构 §5.5）：
 *  1. DedupCooldown：fingerprint=sha1(symbol|interval|direction)，同租户 N 分钟内只发一次
 *  2. 每个渠道落一行 signal_notifications（queued；冷却命中落 deduped）
 *  3. 未命中冷却时投一个 DispatchNotificationJob（notifications 队列，普通 PHP 进程）
 *
 * 本方法在 ai 队列 worker 内被 SignalPipeline 调用，只做 Redis/DB 毫秒级操作 +
 * Job 投递，不直接发外部 HTTP。
 */
class SignalDispatcher implements SignalDispatcherContract
{
    public function __construct(
        private readonly DedupCooldown $dedup,
    ) {
    }

    public function dispatch(Signal $signal, array $channels): void
    {
        $tenantId = (int) $signal->tenantId;
        $signalId = (int) $signal->persistedId;
        $channels = array_values(array_unique(array_filter(array_map('strval', $channels))));

        if ($channels === []) {
            Log::info('信号无通知渠道，跳过分发', ['signal_id' => $signalId]);

            return;
        }

        $fingerprint = sha1($signal->symbol . '|' . $signal->interval . '|' . $signal->direction);
        $cooldownMinutes = (int) config('notifications.dedup.signal_cooldown_minutes', 15);
        $fresh = $this->dedup->tryAcquire($tenantId, 'signal', $fingerprint, $cooldownMinutes);

        $now = Carbon::now();
        $status = $fresh ? 'queued' : 'deduped';

        foreach ($channels as $channel) {
            DB::table('signal_notifications')->insert([
                'tenant_id' => $tenantId,
                'signal_id' => $signalId,
                'channel' => $channel,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! $fresh) {
            Log::info('信号通知命中冷却去重', [
                'signal_id' => $signalId,
                'tenant_id' => $tenantId,
                'channels' => $channels,
                'cooldown_minutes' => $cooldownMinutes,
            ]);

            return;
        }

        DispatchNotificationJob::dispatch($tenantId, $signalId, $channels);

        Log::info('信号通知已入队', [
            'signal_id' => $signalId,
            'channels' => $channels,
        ]);
    }
}
