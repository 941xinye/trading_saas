<?php

declare(strict_types=1);

namespace App\Domain\Signal\Prompters;

/**
 * OpenAI Prompter 适配器。
 *
 * 端点：https://api.openai.com/v1/chat/completions
 * 默认模型：gpt-4o-mini（见 config/ai.php providers.openai）
 *
 * P2 POC 落地：HTTP 客户端 + 限流 + JSON Schema 校验 + 兜底。
 */
class OpenAIAdapter extends AbstractAIPrompter
{
    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return (string) config('ai.providers.openai.model', 'gpt-4o-mini');
    }
}
