<?php

declare(strict_types=1);

/**
 * 信号执行（P2 后续：信号 → 交易联动）。
 *
 * 全部为租户可调参数；信号执行走既有 OrderExecutor 闸门（权益/日配额/幂等）。
 */
return [
    'execution' => [
        // 信号可执行窗口（分钟）：超过则视为过期，避免追高/追空
        'ttl_minutes' => (int) env('SIGNAL_EXECUTION_TTL_MINUTES', 15),
        // 单次执行名义额默认值（USDT）
        'default_notional' => (string) env('SIGNAL_EXECUTION_NOTIONAL', '100'),
        // 单次执行名义额上限（USDT）
        'max_notional' => (string) env('SIGNAL_EXECUTION_MAX_NOTIONAL', '10000'),
        // 单日自动跟单资金总额上限（USDT/租户/自然日，按 sig_ 订单名义额汇总）
        // 0 = 不限；人工一键下单同样计入（共用 sig_ 前缀，防止绕过总额度）
        'daily_notional_cap' => (string) env('SIGNAL_EXECUTION_DAILY_NOTIONAL_CAP', '0'),
    ],
];
