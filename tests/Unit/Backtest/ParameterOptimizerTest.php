<?php

declare(strict_types=1);

namespace Tests\Unit\Backtest;

use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Dto\OptimizationRequest;
use App\Domain\Backtest\Optimization\ParameterOptimizer;
use App\Domain\Strategy\StrategyRegistry;
use Tests\Fakes\OptimizationKlineQuery;
use Tests\TestCase;

/**
 * 参数寻优执行器契约（P11）：排名方向、失败隔离、walk-forward 汇总。
 *
 * 不传 ParameterOptimization 记录 → 纯计算，零 DB 写入。
 */
final class ParameterOptimizerTest extends TestCase
{
    private const FROM = '2023-11-14 00:00:00';

    private const TO = '2023-11-14 00:30:00';

    public function test_grid_mode_picks_highest_score_for_return_objective(): void
    {
        $result = $this->makeOptimizer()->optimize($this->request());

        self::assertSame('grid', $result->mode);
        self::assertSame(2, $result->combinationCount);
        self::assertSame(2, $result->trialCount);
        self::assertSame(0, $result->failedTrialCount);
        self::assertNotNull($result->bestScore);
        self::assertSame($result->bestParams, $result->top[0]['params']);
        self::assertCount(2, $result->top);

        foreach ($result->top as $row) {
            self::assertGreaterThanOrEqual(0, bccomp((string) $result->bestScore, (string) $row['score'], 8));
        }
    }

    public function test_drawdown_objective_prefers_lower_score(): void
    {
        $result = $this->makeOptimizer()->optimize($this->request(objective: 'max_drawdown_pct'));

        self::assertNotNull($result->bestScore);

        foreach ($result->top as $row) {
            self::assertLessThanOrEqual(0, bccomp((string) $result->bestScore, (string) $row['score'], 8));
        }
    }

    public function test_failed_trial_does_not_abort_optimization(): void
    {
        $result = $this->makeOptimizer(failFirstQuery: true)->optimize($this->request());

        self::assertSame(2, $result->trialCount);
        self::assertSame(1, $result->failedTrialCount);
        self::assertNotNull($result->bestScore);
    }

    public function test_walk_forward_produces_fold_details_and_mean_scores(): void
    {
        $result = $this->makeOptimizer()->optimize($this->request(mode: 'walk_forward', folds: 2));

        self::assertSame('walk_forward', $result->mode);
        // 2 组合 × 2 折 × (训练 + 样本外)
        self::assertSame(8, $result->trialCount);
        self::assertCount(2, $result->report['fold_details']);
        self::assertNotNull($result->bestScore);

        foreach ($result->report['fold_details'] as $fold) {
            self::assertArrayHasKey('in_sample_score', $fold);
            self::assertArrayHasKey('out_of_sample_score', $fold);
            self::assertArrayHasKey('best_params', $fold);
        }
    }

    public function test_min_trades_filters_out_all_candidates(): void
    {
        config()->set('optimization.min_trades', 10000);

        $result = $this->makeOptimizer()->optimize($this->request());

        self::assertNull($result->bestParams);
        self::assertNull($result->bestScore);
    }

    private function makeOptimizer(bool $failFirstQuery = false): ParameterOptimizer
    {
        return new ParameterOptimizer(new OptimizationKlineQuery($failFirstQuery), new StrategyRegistry);
    }

    private function request(
        string $mode = 'grid',
        int $folds = 2,
        string $objective = 'total_return_pct',
    ): OptimizationRequest {
        return new OptimizationRequest(
            tenantId: 1,
            strategyCode: 'spot_grid',
            symbol: 'BTCUSDT',
            interval: '1m',
            fromUtc: new \DateTimeImmutable(self::FROM, new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable(self::TO, new \DateTimeZone('UTC')),
            grid: ['grid_count' => [5, 10]],
            mode: $mode,
            folds: $folds,
            objective: $objective,
            assumptions: MatchingAssumptions::fromConfig(capital: '1000'),
            baseParams: ['lower_price' => '90', 'upper_price' => '110', 'investment_amount' => '1000'],
            topN: 5,
        );
    }
}
