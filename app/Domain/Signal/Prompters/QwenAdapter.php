<?php

declare(strict_types=1);

namespace App\Domain\Signal\Prompters;

/**
 * 通义千问 Prompter 适配器。
 *
 * 端点：https://dashscope.aliyuncs.com/compatible-mode/v1
 * 默认模型：qwen-plus（OpenAI 兼容协议）
 *
 * P2 POC 落地：与 DeepSeek 并行的国产大模型备选。
 */
class QwenAdapter extends AbstractAIPrompter
{
    public function name(): string
    {
        return 'qwen';
    }

    public function model(): string
    {
        return (string) config('ai.providers.qwen.model', 'qwen-plus');
    }
}
