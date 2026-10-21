<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notification\Contracts\NotificationPayload;
use App\Domain\Observability\AlertEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * 系统告警评估：读取指标、比对规则、命中则投递通知。
 *
 *   php artisan ops:alerts:check
 *
 * 冷却：同一规则命中后 cooldown_minutes 内不重复通知。
 * 投递渠道由 alerts.channels 配置（默认 log，可加 telegram/mail/wechat_work）。
 */
class OpsAlertsCheckCommand extends Command
{
    protected $signature = 'ops:alerts:check';

    protected $description = 'Evaluate alert rules against metrics and notify on firing rules';

    public function handle(AlertEvaluator $evaluator): int
    {
        if (! (bool) config('alerts.enabled', true)) {
            $this->info('告警已禁用（ALERTS_ENABLED=false）');

            return self::SUCCESS;
        }

        $fired = $evaluator->evaluate();
        if ($fired === []) {
            $this->info('无告警命中');

            return self::SUCCESS;
        }

        $cooldownSeconds = (int) config('alerts.cooldown_minutes', 15) * 60;
        $notified = 0;

        foreach ($fired as $alert) {
            $key = 'alerts:cooldown:'.$alert['rule'];
            try {
                if ((int) Redis::connection()->exists($key) > 0) {
                    continue;
                }
                Redis::connection()->setex($key, $cooldownSeconds, '1');
            } catch (Throwable) {
                // Redis 不可用时仍通知，不设冷却（可能重复，但不丢告警）
            }

            $this->notify($alert);
            $this->warn("[{$alert['severity']}] {$alert['message']}");
            $notified++;
        }

        $this->info("命中 ".count($fired)." 条规则，已通知 {$notified} 条（其余在冷却期）");

        return self::SUCCESS;
    }

    /**
     * @param  array{rule:string,severity:string,message:string,value:float}  $alert
     */
    private function notify(array $alert): void
    {
        $severity = strtoupper($alert['severity']);
        $title = "[{$severity} 告警] {$alert['message']}";
        $context = [
            'alert_rule' => $alert['rule'],
            'severity' => $alert['severity'],
            'value' => $alert['value'],
        ];

        // 始终落结构化日志
        Log::warning('系统告警：'.$alert['message'], $context);

        $payload = new NotificationPayload(
            title: $title,
            fields: [
                '规则' => $alert['rule'],
                '严重级别' => $alert['severity'],
                '当前值' => (string) $alert['value'],
            ],
            footer: now()->toIso8601String(),
            context: $context,
        );

        $channels = (array) config('alerts.channels', ['log']);
        $channelClasses = (array) config('notifications.channel_classes', []);

        foreach ($channels as $channelName) {
            $class = $channelClasses[$channelName] ?? null;
            if ($class === null) {
                continue;
            }
            try {
                app($class)->send($payload);
            } catch (Throwable $e) {
                Log::warning('告警渠道投递失败', [
                    'channel' => $channelName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
