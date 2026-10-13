<?php

declare(strict_types=1);

namespace App\Domain\Signal\Contracts;

/**
 * 信号分发器接口契约。
 *
 * 每个 channel 分发为独立 Job（queue=notifications），互不阻塞。
 * 冷却去重：同 (tenant, symbol, direction, interval) 在 N 分钟内只通知一次。
 *
 * 渠道：telegram|wechat_work|mail|websocket
 */
interface SignalDispatcher
{
    /**
     * 分发信号到指定渠道集合。
     *
     * @param Signal      $signal   信号载荷
     * @param string[]    $channels 渠道列表 telegram|wechat_work|mail|websocket
     */
    public function dispatch(Signal $signal, array $channels): void;
}
