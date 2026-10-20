<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * DSL 风控段（contracts §3 / data-model §2.5）。
 *
 * 白名单映射，未知键即拒（FR-002，禁止静默）。
 */
final readonly class DslRisk
{
    public function __construct(
        public ?string $maxPositionPct = null,
        public int $cooldownBars = 0,
        public ?string $stopLossPct = null,
        public ?string $takeProfitPct = null,
        public int $maxEntriesPerBar = 1,
    ) {}
}
