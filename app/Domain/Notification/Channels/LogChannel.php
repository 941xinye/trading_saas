<?php

declare(strict_types=1);

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Contracts\NotificationPayload;
use App\Domain\Notification\Contracts\NotifierChannel;
use Illuminate\Support\Facades\Log;

/**
 * 日志渠道（POC 默认渠道）。
 *
 * 无外部依赖，把通知载荷写入应用日志（storage/logs/laravel.log）：
 * title 作为消息文本，context 作为结构化字段，供全链路离线验证与
 * 长驻实跑时 grep 审计。信号/风控/对账链路共用本实现。
 */
class LogChannel implements NotifierChannel
{
    public function name(): string
    {
        return 'log';
    }

    public function send(NotificationPayload $payload): ?string
    {
        Log::info($payload->title, $payload->context);

        return 'logged';
    }
}
