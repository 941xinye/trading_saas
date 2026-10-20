<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * DSL 元信息（contracts §3 / data-model §2.1）。
 */
final readonly class DslMeta
{
    public function __construct(
        public string $name,
        public string $symbol,
        public string $interval,
        public string $direction,
        public string $trigger,
    ) {}
}
