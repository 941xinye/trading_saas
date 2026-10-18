<?php

declare(strict_types=1);

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Contracts\NotificationPayload;
use App\Domain\Notification\Contracts\NotifierChannel;
use Illuminate\Support\Facades\Http;

/**
 * 企业微信群机器人渠道（未配置 webhook 时 send 抛异常，由调用方落 failed）。
 *
 * 配置：WECHAT_WORK_WEBHOOK（群机器人 webhook 完整地址）。
 * 使用 markdown 消息类型：title 加粗首行 → fields 逐行 label：value → footer 引用行。
 * 响应 errcode=0 视为成功；企微 webhook 不返回消息 ID，send 恒返 null。
 * 信号/风控/对账链路共用本实现（config notifications.channel_classes 注册生效）。
 */
class WeComChannel implements NotifierChannel
{
    public function name(): string
    {
        return 'wechat_work';
    }

    public function send(NotificationPayload $payload): ?string
    {
        $cfg = (array) config('notifications.channels.wechat_work');
        $webhook = trim((string) ($cfg['webhook'] ?? ''));

        if ($webhook === '') {
            throw new \RuntimeException('企微未配置 webhook');
        }

        $response = Http::timeout((int) ($cfg['timeout'] ?? 10))
            ->asJson()
            ->acceptJson()
            ->post($webhook, [
                'msgtype' => 'markdown',
                'markdown' => ['content' => $this->format($payload)],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('企微 webhook HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300));
        }

        $result = $response->json();
        if (($result['errcode'] ?? null) !== 0) {
            throw new \RuntimeException('企微 webhook 返回 errcode=' . ($result['errcode'] ?? 'null') . ': ' . mb_substr($response->body(), 0, 300));
        }

        return null;
    }

    private function format(NotificationPayload $payload): string
    {
        $lines = ['**' . $payload->title . '**'];

        foreach ($payload->fields as $label => $value) {
            $lines[] = (string) $label . '：' . $value;
        }

        if ($payload->footer !== null) {
            $lines[] = '> ' . $payload->footer;
        }

        return implode("\n", $lines);
    }
}
