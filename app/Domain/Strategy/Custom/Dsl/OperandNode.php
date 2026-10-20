<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * DSL 操作数（contracts §3 / data-model §2.4）。
 *
 * 按 `type` 判别：`indicator` / `price` / `const`。
 */
final readonly class OperandNode
{
    public function __construct(
        public string $type,
        public ?string $indicator = null,
        public ?int $period = null,
        public ?string $source = null,
        public ?string $band = null,
        public ?string $stdDev = null,
        public ?string $price = null,
        public ?string $const = null,
    ) {}

    public static function indicator(
        string $indicator,
        ?int $period = null,
        ?string $source = null,
        ?string $band = null,
        ?string $stdDev = null,
    ): self {
        return new self(
            type: 'indicator',
            indicator: $indicator,
            period: $period,
            source: $source,
            band: $band,
            stdDev: $stdDev,
        );
    }

    public static function price(string $price): self
    {
        return new self(type: 'price', price: $price);
    }

    public static function const(string $const): self
    {
        return new self(type: 'const', const: $const);
    }
}
