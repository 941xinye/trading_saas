<?php

/**
 * 参数寻优配置（P11 / spec 008 P4 调优扩展）。
 *
 * 网格搜索 + walk-forward 切分，全部复用 BacktestRunner（纯内存、零交易所调用）。
 */

return [

    // 单次寻优最大组合数（笛卡尔积上限，超限拒绝而非静默截断）
    'max_combinations' => (int) env('OPTIMIZATION_MAX_COMBINATIONS', 200),

    // walk-forward 默认折数（区间切分为 folds+1 段，前段训练、后段样本外）
    'default_folds' => (int) env('OPTIMIZATION_DEFAULT_FOLDS', 3),

    // 寻优目标：key = 绩效报告字段；max_drawdown_pct 越小越好，其余越大越好
    'objectives' => [
        'total_return_pct' => '总收益率',
        'sharpe_ratio' => 'Sharpe',
        'max_drawdown_pct' => '最大回撤',
        'profit_factor' => '盈亏比',
    ],

    // 最少成交笔数：低于该值的组合不参与排名（score = null）
    'min_trades' => (int) env('OPTIMIZATION_MIN_TRADES', 0),

    // 默认输出 Top N 参数组
    'top_n' => (int) env('OPTIMIZATION_TOP_N', 5),

    // 各策略允许寻优的参数白名单（未列出的键直接拒绝，防止注入无效参数）
    'strategies' => [
        'spot_grid' => ['lower_price', 'upper_price', 'grid_count', 'investment_amount'],
        'futures_grid' => ['lower_price', 'upper_price', 'grid_count', 'margin', 'leverage'],
        'spot_dca' => ['amount_per_order', 'interval_minutes', 'max_orders'],
    ],

];
