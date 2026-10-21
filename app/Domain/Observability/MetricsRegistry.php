<?php

declare(strict_types=1);

namespace App\Domain\Observability;

/**
 * 指标注册表：声明所有对外暴露的指标元数据（名称 / 类型 / 帮助 / 标签顺序）。
 *
 * 新增指标须在此登记，否则 /metrics 端点不会输出。
 */
final class MetricsRegistry
{
    /**
     * @return array<string,array{type:string,help:string,labels:array<int,string>}>
     */
    public static function all(): array
    {
        return [
            'trading_orders_total' => [
                'type' => 'counter',
                'help' => 'Total orders placed by side and final status.',
                'labels' => ['tenant_id', 'side', 'status'],
            ],
            'trading_signals_total' => [
                'type' => 'counter',
                'help' => 'Total signals received and auto-follow decisions by source and decision.',
                'labels' => ['tenant_id', 'source', 'decision'],
            ],
            'trading_queue_backlog' => [
                'type' => 'gauge',
                'help' => 'Current queue backlog depth by queue name.',
                'labels' => ['queue'],
            ],
            'trading_breaker_open' => [
                'type' => 'gauge',
                'help' => 'Circuit breaker open state per strategy instance (1=open, 0=closed).',
                'labels' => ['tenant_id', 'instance_id'],
            ],
        ];
    }

    public static function typeOf(string $name): string
    {
        return (string) (self::all()[$name]['type'] ?? 'counter');
    }
}
