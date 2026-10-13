<?php

declare(strict_types=1);

namespace App\Domain\Signal\Prompters;

/**
 * 本地 Ollama Prompter 适配器。
 *
 * 端点：http://127.0.0.1:11434（本地部署）
 * 默认模型：qwen2.5:14b
 *
 * 适用场景：私有化部署、零外发延迟、无 API Key 成本。
 * 超时 60s（本地大模型推理慢）。
 * P2 POC 落地：作为离线/低成本的兜底备选。
 */
class LocalOllamaAdapter extends AbstractAIPrompter
{
    public function name(): string
    {
        return 'local';
    }

    public function model(): string
    {
        return (string) config('ai.providers.local.model', 'qwen2.5:14b');
    }
}
