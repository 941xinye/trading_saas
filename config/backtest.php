<?php

/**
 * 回测引擎配置（spec 008 / data-model.md / research.md）
 *
 * 回测区间与覆盖率阈值、撮合假设默认值、并发控制参数。
 * 回测全程零真实交易所调用（FR-004）；回测不计配额（FR-011）。
 */

return [

    // 回测区间上限（天，FR-012）
    'max_range_days' => (int) env('BACKTEST_MAX_RANGE_DAYS', 365),

    // K 线覆盖率下限（%）：实际 bar 数/理论 bar 数 ×100 低于此值抛 BacktestDataCoverageException（FR-013）
    'min_coverage_pct' => (float) env('BACKTEST_MIN_COVERAGE_PCT', 80),

    // 单飞锁 TTL（秒）：回测执行上限兜底（research R-007）
    'lock_ttl_sec' => (int) env('BACKTEST_LOCK_TTL_SEC', 120),

    // 崩溃任务收割阈值（秒）：running 且 updated_at 停滞超过此值被 backtest:reap-stale 标 failed
    'stale_after_sec' => (int) env('BACKTEST_STALE_AFTER_SEC', 240),

    // 租户并发回测上限（按套餐差异化，FR-009；超限拒绝不排队，research R-007）
    'concurrency' => [
        'free' => (int) env('BACKTEST_CONCURRENCY_FREE', 1),
        'pro' => (int) env('BACKTEST_CONCURRENCY_PRO', 2),
        'enterprise' => (int) env('BACKTEST_CONCURRENCY_ENTERPRISE', 4),
    ],

    // 撮合假设默认值（可配置，报告显式标注；spec Assumptions）
    'defaults' => [
        'initial_capital' => env('BACKTEST_DEFAULT_CAPITAL', '1000'),
        // 默认参考 Binance 现货 taker 费率 0.1%
        'fee_rate' => env('BACKTEST_DEFAULT_FEE_RATE', '0.001'),
        'slippage_bps' => (int) env('BACKTEST_DEFAULT_SLIPPAGE_BPS', 5),
    ],

    // 撮合假设合法域（BacktestRunRequest 校验，data-model.md 验证规则）
    'limits' => [
        'fee_rate_max' => '0.01',
        'slippage_bps_max' => 100,
    ],

    // 支持回测的策略白名单（FR-001；须为 strategy.implemented 子集）
    'strategies' => ['spot_grid', 'spot_dca', 'futures_grid', 'custom_dsl'],

];
