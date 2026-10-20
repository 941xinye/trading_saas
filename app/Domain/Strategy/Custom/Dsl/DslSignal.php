<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * 解释器产出的单条信号（contracts §5 / data-model §4.2）。
 *
 * 数值换算（`qty_pct` → base 数量）涉及资金与持仓，由 `DslStrategy` 在 shouldOpen/shouldClose
 * 阶段用 bcmath 完成；解释器只透出原始 `qty` 与 `qtyPct`（解释器不触达资金）。
 */
final readonly class DslSignal
{
    public function __construct(
        public string $section,     // filter|entry|exit
        public int $ruleIndex,
        public string $action,      // 动作枚举
        public ?string $qty = null, // base 数量（显式 `qty`，bcmath 字符串）
        public ?string $qtyPct = null,
        public ?string $note = null,
    ) {}
}
