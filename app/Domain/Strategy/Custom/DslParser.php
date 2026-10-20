<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom;

use App\Domain\Strategy\Custom\Dsl\DslCompiler;
use App\Domain\Strategy\Custom\Dsl\DslDiagnostic;
use App\Domain\Strategy\Custom\Dsl\DslProgram;
use App\Domain\Strategy\Custom\Dsl\DslValidationException;
use App\Domain\Strategy\Custom\Dsl\DslYamlParser;

/**
 * DSL 解析门面（plan WS1 / contracts §2）。
 *
 * 编排：规模上限预检（字节/行）→ `DslYamlParser`（受限 YAML 子集）→ `DslCompiler`（结构校验 + AST）。
 * 非法 DSL 抛 `DslValidationException`（携带行/字段定位），MUST NOT 进入运行态（FR-005）。
 */
final class DslParser
{
    public function __construct(
        private readonly ?DslYamlParser $yaml = null,
        private readonly ?DslCompiler $compiler = null,
    ) {}

    /**
     * @throws DslValidationException 非法 DSL（FR-002/FR-005），诊断含 行/字段
     */
    public function parse(string $script): DslProgram
    {
        $config = $this->resolveConfig();

        $this->assertWithinLimits($script, $config);

        $yaml = $this->yaml ?? new DslYamlParser();
        $raw = $yaml->parse($script);

        $compiler = $this->compiler ?? new DslCompiler($config);

        return $compiler->compile($raw, sha1($script), $yaml->lineMap());
    }

    /**
     * @param array<string,mixed> $config
     *
     * @throws DslValidationException
     */
    private function assertWithinLimits(string $script, array $config): void
    {
        $maxBytes = (int) ($config['max_script_bytes'] ?? 8192);
        $maxLines = (int) ($config['max_lines'] ?? 200);

        $diagnostics = [];

        if (strlen($script) > $maxBytes) {
            $diagnostics[] = new DslDiagnostic(
                DslDiagnostic::LEVEL_ERROR,
                1,
                '$',
                sprintf('脚本字节数 %d 超过上限 %d', strlen($script), $maxBytes),
            );
        }

        $trimmed = rtrim($script, "\r\n");
        $lineCount = $trimmed === '' ? 0 : substr_count($trimmed, "\n") + 1;
        if ($lineCount > $maxLines) {
            $diagnostics[] = new DslDiagnostic(
                DslDiagnostic::LEVEL_ERROR,
                1,
                '$',
                sprintf('脚本行数 %d 超过上限 %d', $lineCount, $maxLines),
            );
        }

        if ($diagnostics !== []) {
            throw new DslValidationException($diagnostics);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveConfig(): array
    {
        try {
            return (array) config('strategy.dsl', []);
        } catch (\Throwable) {
            return [];
        }
    }
}
