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

    /** 未建模因素清单（FR-013：报告必须显式标注） */
    public const NARRATIVE_UNMODELED = '未建模因素：盘口深度、部分成交、撮合延迟';

    /** futures_grid 资金费率免责（US4-3；历史资金费率无数据源） */
    public const NARRATIVE_FUNDING_DISCLAIMER = '未含资金费率影响（永续合约历史资金费率数据不可得，持仓成本未计入报告）';

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
     * 自 config 默认值构造（未传参数取 config/backtest.php defaults）。
     */
    public static function fromConfig(
        ?string $capital = null,
        ?string $feeRate = null,
        ?int $slippageBps = null,
        ?string $strategyCode = null,
    ): self {
        $defaults = config('backtest.defaults');

        $fee = (string) ($feeRate ?? $defaults['fee_rate']);
        $slippage = (int) ($slippageBps ?? $defaults['slippage_bps']);
        $initial = (string) ($capital ?? $defaults['initial_capital']);

        return new self(
            feeRate: $fee,
            slippageBps: $slippage,
            initialCapital: $initial,
            narrative: self::composeNarrative($fee, $slippage, $initial, $strategyCode),
        );
    }

    /**
     * 按策略生成口径说明文案（US4：报告假设区展示）。
     *
     * 基准口径：bar 收盘价 ± slippage_bps 成交（买入上浮/卖出下浮）；
     * futures_grid 追加资金费率免责标注（US4-3）。
     */
    public static function composeNarrative(
        string $feeRate,
        int $slippageBps,
        string $initialCapital,
        ?string $strategyCode = null,
    ): string {
        $narrative = sprintf(
            '成交口径：以 bar 收盘价 ± %dbps 成交（买入价上浮、卖出价下浮）；手续费率 %s（按成交额计提）；初始资金 %s USDT；%s',
            $slippageBps,
            $feeRate,
            $initialCapital,
            self::NARRATIVE_UNMODELED,
        );

        if ($strategyCode === 'futures_grid') {
            $narrative .= '；'.self::NARRATIVE_FUNDING_DISCLAIMER;
        }

        return $narrative;
    }

    /**
     * 生成携带指定策略口径说明的新快照（readonly 值对象，不可原地改）。
     */
    public function forStrategy(string $strategyCode): self
    {
        return new self(
            feeRate: $this->feeRate,
            slippageBps: $this->slippageBps,
            initialCapital: $this->initialCapital,
            narrative: self::composeNarrative(
                $this->feeRate,
                $this->slippageBps,
                $this->initialCapital,
                $strategyCode,
            ),
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
        $feeRate = (string) ($data['fee_rate'] ?? '0.001');
        $slippageBps = (int) ($data['slippage_bps'] ?? 5);
        $initialCapital = (string) ($data['initial_capital'] ?? '1000');
        $narrative = (string) ($data['narrative'] ?? '');

        return new self(
            feeRate: $feeRate,
            slippageBps: $slippageBps,
            initialCapital: $initialCapital,
            // 旧快照无 narrative 时按同一口径补齐（报告可读性）
            narrative: $narrative !== '' ? $narrative : self::composeNarrative($feeRate, $slippageBps, $initialCapital),
        );
    }
}
