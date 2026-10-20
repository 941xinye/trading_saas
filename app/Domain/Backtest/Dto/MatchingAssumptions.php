<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Dto;

/**
 * 撮合假设快照（不可变值对象）。
 *
 * 随任务快照持久化到 backtest_runs.assumptions，保证报告可复现。
 * 校验规则见 data-model.md「验证规则」节。
 */
final readonly class MatchingAssumptions
{
    /** 手续费率上限（硬编码，与 config/backtest.php limits 对齐） */
    private const FEE_RATE_MAX = '0.01';

    /** 滑点 bps 上限（硬编码，与 config/backtest.php limits 对齐） */
    private const SLIPPAGE_BPS_MAX = 100;

    public function __construct(
        /** 手续费率（如 '0.001' = 0.1%） */
        public string $feeRate,
        /** 滑点 bps（如 5 = 0.05%） */
        public int $slippageBps,
        /** 初始资金（bcmath 字符串） */
        public string $initialCapital,
        /** 口径说明文案（报告展示用） */
        public string $narrative = '',
    ) {
        if (bccomp($this->initialCapital, '0', 8) !== 1) {
            throw new \InvalidArgumentException('initial_capital 必须 > 0');
        }
        if (bccomp($this->feeRate, '0', 8) === -1 || bccomp($this->feeRate, self::FEE_RATE_MAX, 8) === 1) {
            throw new \InvalidArgumentException('fee_rate 须在 [0, '.self::FEE_RATE_MAX.'] 区间');
        }
        if ($this->slippageBps < 0 || $this->slippageBps > self::SLIPPAGE_BPS_MAX) {
            throw new \InvalidArgumentException('slippage_bps 须在 [0, '.self::SLIPPAGE_BPS_MAX.'] 区间');
        }
    }

    /**
     * 自 config 默认值构造。
     */
    public static function fromConfig(?string $capital = null, ?string $feeRate = null, ?int $slippageBps = null): self
    {
        $defaults = config('backtest.defaults');

        return new self(
            feeRate: $feeRate ?? $defaults['fee_rate'],
            slippageBps: $slippageBps ?? $defaults['slippage_bps'],
            initialCapital: $capital ?? $defaults['initial_capital'],
            narrative: 'bar 收盘价 ± slippage_bps 成交；未建模盘口深度/部分成交/资金费率',
        );
    }

    /**
     * @return array{fee_rate:string, slippage_bps:int, initial_capital:string, narrative:string}
     */
    public function toArray(): array
    {
        return [
            'fee_rate' => $this->feeRate,
            'slippage_bps' => $this->slippageBps,
            'initial_capital' => $this->initialCapital,
            'narrative' => $this->narrative,
        ];
    }

    /**
     * 自数组反序列化（Job 恢复 run 快照用）。
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            feeRate: (string) ($data['fee_rate'] ?? '0.001'),
            slippageBps: (int) ($data['slippage_bps'] ?? 5),
            initialCapital: (string) ($data['initial_capital'] ?? '1000'),
            narrative: (string) ($data['narrative'] ?? ''),
        );
    }
}
