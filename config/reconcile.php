<?php

/**
 * 账实对账配置（P5.5 步骤 1）。
 *
 * 铁律：对账只报告 + 暂停，不自动改账/补单/平仓。
 * 比对全程 bcmath 字符串金额，时间一律 UTC。
 */

return [

    // 默认周期桶（reconcile:run --period 可覆盖）
    'default_period' => '5m',

    // 容差（dust）：数量差 ≤ qty_tolerance「或」等值差 ≤ quote_tolerance 均视为 ok
    'tolerance' => [
        'qty' => '0.000001',        // base 资产数量（8 位精度下的 dust）
        'quote' => '5',            // 等值 USDT
    ],

    // 超过该等值阈值（USDT）无条件 critical
    'critical_quote' => '50',

    // 连续 unknown 达到该次数才告警（网络抖动不误杀）
    'unknown_alert_after' => 3,

    // unknown 连续计数 Redis key
    'unknown_key_prefix' => 'tenant:{tenant_id}:reconcile:{instance_id}:unknown',

    // critical / unknown 告警渠道（复用 P4 risk_notifications + RiskNotificationJob 链路）
    'notify_channels' => ['log', 'telegram'],

    // 告警去重冷却（分钟），避免每周期重复刷屏
    'dedup_ttl_minutes' => 15,

    // 报价资产后缀（最长匹配，用于从 symbol 拆 base/quote）
    'quote_suffixes' => ['USDT', 'USDC', 'TUSD', 'FDUSD', 'BUSD', 'BTC', 'ETH', 'BNB'],
];
