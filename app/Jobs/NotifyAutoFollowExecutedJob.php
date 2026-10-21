<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Notification\Contracts\NotifierChannel;
use App\Domain\Notification\Payloads\AutoFollowExecutedPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 自动跟单成交通知 Job（notifications 队列，普通 PHP 进程，可做外部 HTTP）。
 *
 * 下单成功后由 AutoExecuteSignalJob 投递；按订阅配置渠道逐条发送，
 * 单渠道失败只记日志，不影响其他渠道，也不回滚已成交订单。
 * 队列故障由 AutoExecuteSignalJob 的 try/catch 吞掉，订单本身已落库。
 */
class NotifyAutoFollowExecutedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  string[]  $channels
     * @param  array<string, mixed>  $execution
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly array $channels,
        public readonly array $execution,
    ) {
        // Laravel 13 Queueable trait 自带 $queue 属性，不能重复声明，走 onQueue()
        $this->onQueue('notifications');
    }

    /**
     * @return int[]
     */
    public function backoff(): array
    {
        return (array) config('notifications.retry.backoff_seconds', [10, 30, 90]);
    }

    public function handle(): void
    {
        $payload = AutoFollowExecutedPayload::fromExecution($this->execution);
        $channelClasses = (array) config('notifications.channel_classes', []);

        foreach ($this->channels as $channelName) {
            $channelClass = $channelClasses[$channelName] ?? null;
            if ($channelClass === null) {
                Log::info('自动跟单成交通知跳过未实现渠道', [
                    'tenant_id' => $this->tenantId,
                    'channel' => $channelName,
                    'signal_id' => (int) ($this->execution['signal_id'] ?? 0),
                ]);

                continue;
            }

            /** @var NotifierChannel $channel */
            $channel = app($channelClass);

            try {
                $messageId = $channel->send($payload);
                Log::info('自动跟单成交通知已发送', [
                    'tenant_id' => $this->tenantId,
                    'channel' => $channelName,
                    'signal_id' => (int) ($this->execution['signal_id'] ?? 0),
                    'provider_message_id' => $messageId,
                ]);
            } catch (Throwable $e) {
                Log::warning('自动跟单成交通知发送失败', [
                    'tenant_id' => $this->tenantId,
                    'channel' => $channelName,
                    'signal_id' => (int) ($this->execution['signal_id'] ?? 0),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
