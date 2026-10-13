<?php

/**
 * AI Provider 配置
 *
 * 抽象各 LLM Provider 的接入参数、限流、prompt 截断、超时与降级。
 * 无效配置时返回 null 不阻塞主流程（graceful degradation）。
 */

return [

    'default' => env('AI_DEFAULT_PROVIDER', 'deepseek'),

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'api_base' => env('OPENAI_API_BASE', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_DEFAULT_MODEL', 'gpt-4o-mini'),
            'timeout' => 30,
            // 模型上下文上限（token），prompt 超过 60% 时按时间窗口裁剪历史 bar
            'context_limit' => 16000,
            'truncate_ratio' => 0.6,
        ],
        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY'),
            'api_base' => env('DEEPSEEK_API_BASE', 'https://api.deepseek.com/v1'),
            'model' => env('DEEPSEEK_DEFAULT_MODEL', 'deepseek-chat'),
            'timeout' => 30,
            'context_limit' => 32000,
            'truncate_ratio' => 0.6,
        ],
        'qwen' => [
            'api_key' => env('QWEN_API_KEY'),
            'api_base' => env('QWEN_API_BASE', 'https://dashscope.aliyuncs.com/compatible-mode/v1'),
            'model' => env('QWEN_DEFAULT_MODEL', 'qwen-plus'),
            'timeout' => 30,
            'context_limit' => 32000,
            'truncate_ratio' => 0.6,
        ],
        'local' => [
            'api_base' => env('OLLAMA_API_BASE', 'http://127.0.0.1:11434'),
            'model' => env('OLLAMA_DEFAULT_MODEL', 'qwen2.5:14b'),
            'timeout' => 60,
            'context_limit' => 32000,
            'truncate_ratio' => 0.6,
        ],
    ],

    // 限流令牌桶（按 tenant:provider 维度）
    'rate_limiter' => [
        'driver' => 'redis',
        'key_prefix' => 'tenant:{tenant_id}:ai:ratelimit:{provider}',
        // 每分钟最多调用次数
        'requests_per_minute' => 30,
        // 超限直接走 RuleEngineFallback
        'fallback_on_throttle' => true,
    ],

    // 信号降级规则引擎配置
    'fallback' => [
        'enabled' => true,
        // 基于技术指标：MA/RSI/MACD/Bollinger/ATR
        'indicators' => [
            'ma_periods' => [7, 25, 99],
            'rsi_period' => 14,
            'macd' => ['fast' => 12, 'slow' => 26, 'signal' => 9],
            'bollinger' => ['period' => 20, 'std_dev' => 2],
            'atr_period' => 14,
        ],
    ],

    // 信号价差校验（AI 完成时若价格已偏离入场价超过阈值则丢弃信号）
    'signal_price_guard' => [
        'enabled' => true,
        'max_deviation_pct' => 1.0,
    ],

    // Prompt 模板
    'prompts' => [
        'signal_analysis' => 'ai.signal_analysis',
    ],
];
