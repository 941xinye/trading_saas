<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * DSL 校验失败异常（contracts §4）。
 *
 * 在装载/保存/试装载期抛出，携带 `DslDiagnostic[]`；message 由 error 级诊断拼接，
 * 保证错误可定位且不静默（FR-002 / FR-005）。
 */
final class DslValidationException extends \RuntimeException
{
    /**
     * @param DslDiagnostic[] $diagnostics
     */
    public function __construct(public readonly array $diagnostics)
    {
        parent::__construct(self::buildMessage($diagnostics));
    }

    /**
     * @return DslDiagnostic[]
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @param DslDiagnostic[] $diagnostics
     */
    private static function buildMessage(array $diagnostics): string
    {
        $errors = array_values(array_filter(
            $diagnostics,
            static fn (DslDiagnostic $d): bool => $d->isError(),
        ));

        if ($errors === []) {
            return 'DSL 校验失败';
        }

        $parts = [];
        foreach ($errors as $d) {
            $parts[] = sprintf('第 %d 行 [%s] %s', $d->line, $d->field, $d->message);
        }

        return 'DSL 校验失败：'.implode('；', $parts);
    }
}
