<?php

declare(strict_types=1);

namespace App\Domain\Signal\Prompters;

/**
 * DeepSeek Prompter 适配器。
 *
 * 端点：https://api.deepseek.com/v1/chat/completions
 * 默认模型：deepseek-chat（OpenAI 兼容协议）
 *
 * 默认 AI Provider（config/ai.php default = deepseek），P2 POC 主路落地。
 */
class DeepSeekAdapter extends AbstractAIPrompter
{
    public function name(): string
    {
        return 'deepseek';
    }

    public function model(): string
    {
        return (string) config('ai.providers.deepseek.model', 'deepseek-chat');
    }
}
