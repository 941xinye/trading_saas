<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Domain\Notification\Channels\LogChannel;
use App\Domain\Notification\Contracts\NotificationPayload;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * LogChannel 通用载荷测试（default 组）。
 *
 * title 作为日志消息、context 作为结构化字段，返回 'logged' 供审计落库。
 */
class LogChannelTest extends TestCase
{
    public function test_logs_title_with_context_and_returns_logged(): void
    {
        Log::spy();

        $result = (new LogChannel)->send(new NotificationPayload(
            title: '⚠️ 熔断告警',
            fields: ['实例' => '#5'],
            context: ['notification_id' => 11, 'tenant_id' => 99],
        ));

        static::assertSame('logged', $result);

        Log::shouldHaveReceived('info')
            ->once()
            ->with('⚠️ 熔断告警', ['notification_id' => 11, 'tenant_id' => 99]);
    }
}
