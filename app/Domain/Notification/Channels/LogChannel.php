<?php

declare(strict_types=1);

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Contracts\NotifierChannel;
use App\Domain\Signal\Contracts\Signal;
use Illuminate\Support\Facades\Log;

/**
 * 日志渠道（P2 POC 默认渠道）。
 *
 * 无外部依赖，把信号结构化写入应用日志（storage/logs/laravel.log），
 * 供全链路离线验证与长驻实跑时 grep 审计。
 */
class LogChannel implements NotifierChannel
{
    public function name(): string
    {
        return 'log';
    }

    public function send(Signal $signal): ?string
    {
        $arrow = $signal->direction === 'long' ? '🟢 做多 LONG' : '🔴 做空 SHORT';

        Log::info('[SIGNAL] 信号通知', [
            'signal_id' => $signal->persistedId,
            'tenant_id' => $signal->tenantId,
            'direction' => $arrow,
            'symbol' => $signal->symbol,
            'interval' => $signal->interval,
            'entry' => $signal->entryPrice,
            'exit' => $signal->exitPrice,
            'stop' => $signal->stopLoss,
            'confidence' => $signal->confidence,
            'source' => $signal->source,
            'rationale' => $signal->rationale,
        ]);

        return 'logged';
    }
}
