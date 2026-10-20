<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * 校验诊断（spec 006 / data-model §3.1）。
 *
 * 驱动编辑器反馈与装载拒绝：`error` 级诊断 ⇒ 拒绝编译（FR-005）；
 * 编辑器仅在收集到 `warning` 时不阻断。
 */
final readonly class DslDiagnostic
{
    public const LEVEL_ERROR = 'error';

    public const LEVEL_WARNING = 'warning';

    public function __construct(
        public string $level,
        public int $line,
        public string $field,
        public string $message,
    ) {}

    public function isError(): bool
    {
        return $this->level === self::LEVEL_ERROR;
    }

    /**
     * @return array{level:string,line:int,field:string,message:string}
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'line' => $this->line,
            'field' => $this->field,
            'message' => $this->message,
        ];
    }
}
