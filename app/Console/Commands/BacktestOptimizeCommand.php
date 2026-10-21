<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Dto\OptimizationRequest;
use App\Domain\Backtest\Dto\OptimizationResult;
use App\Domain\Backtest\Models\ParameterOptimization;
use App\Domain\Backtest\Services\OptimizationService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * backtest:optimize 参数寻优命令（P11）。
 *
 * grid 模式全区间逐组合回测；walk-forward 模式分折训练/样本外并取均值。
 * 复用 BacktestRunner，同步执行，零交易所调用。
 * --dry-run 只打印结果不落库。
 */
final class BacktestOptimizeCommand extends Command
{
    protected $signature = 'backtest:optimize
        {strategy : 策略代码（spot_grid|futures_grid|spot_dca）}
        {symbol : 交易对，如 BTCUSDT}
        {interval : K线周期（1m/5m/15m/1h）}
        {--from= : 起始时间 UTC（Y-m-d H:i:s）}
        {--to= : 结束时间 UTC（Y-m-d H:i:s）}
        {--tenant=1 : 租户 ID}
        {--grid= : 参数网格 JSON 或 @文件路径，如 {"grid_count":[5,10],"lower_price":{"min":90,"max":100,"step":5}}}
        {--params= : 固定参数 JSON（不参与寻优）}
        {--mode=grid : grid|walk_forward}
        {--folds=3 : walk-forward 折数}
        {--objective=total_return_pct : 寻优目标（total_return_pct|sharpe_ratio|max_drawdown_pct|profit_factor）}
        {--capital= : 初始资金（默认 config backtest.defaults.initial_capital）}
        {--fee-rate= : 手续费率（默认 config backtest.defaults.fee_rate）}
        {--slippage= : 滑点 bps（默认 config backtest.defaults.slippage_bps）}
        {--top=5 : 输出 Top N 参数组}
        {--dry-run : 只计算不落库}';

    protected $description = '参数网格搜索 / walk-forward 寻优，输出最优参数组';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');

        $fromUtc = $this->parseUtc((string) $this->option('from'));
        $toUtc = $this->parseUtc((string) $this->option('to'));
        if ($fromUtc === null || $toUtc === null) {
            $this->error('--from 与 --to 必填，格式 Y-m-d H:i:s（UTC）');

            return self::INVALID;
        }

        $grid = $this->parseJson((string) $this->option('grid'), '--grid');
        if ($grid === null) {
            return self::INVALID;
        }

        $baseParams = [];
        if ($this->option('params') !== null) {
            $decoded = json_decode((string) $this->option('params'), true);
            if (! is_array($decoded)) {
                $this->error('--params 须为合法 JSON 对象');

                return self::INVALID;
            }
            $baseParams = $decoded;
        }

        $assumptions = MatchingAssumptions::fromConfig(
            capital: $this->option('capital') !== null ? (string) $this->option('capital') : null,
            feeRate: $this->option('fee-rate') !== null ? (string) $this->option('fee-rate') : null,
            slippageBps: $this->option('slippage') !== null ? (int) $this->option('slippage') : null,
            strategyCode: (string) $this->argument('strategy'),
        );

        try {
            $request = new OptimizationRequest(
                tenantId: $tenantId,
                strategyCode: (string) $this->argument('strategy'),
                symbol: (string) $this->argument('symbol'),
                interval: (string) $this->argument('interval'),
                fromUtc: $fromUtc,
                toUtc: $toUtc,
                grid: $grid,
                mode: (string) $this->option('mode'),
                folds: (int) $this->option('folds'),
                objective: (string) $this->option('objective'),
                assumptions: $assumptions,
                baseParams: $baseParams,
                topN: (int) $this->option('top'),
            );
        } catch (Throwable $e) {
            $this->error('参数非法：'.$e->getMessage());

            return self::INVALID;
        }

        TenantContext::initialize($tenantId);
        try {
            $service = app(OptimizationService::class);

            if ($this->option('dry-run')) {
                $this->renderResult($service->dryRun($request), null);

                return self::SUCCESS;
            }

            $record = $service->run($request);
            $this->info("寻优任务完成：optimization_id={$record->id} status={$record->status->value}");
            $this->renderResult($this->resultFromRecord($record), $record);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('寻优失败：'.$e->getMessage());

            return self::FAILURE;
        } finally {
            TenantContext::clear();
        }
    }

    private function renderResult(OptimizationResult $result, ?ParameterOptimization $record): void
    {
        $this->newLine();
        $this->info('======== 参数寻优结果 ========');
        $this->line(sprintf(
            'mode: %s | objective: %s | combinations: %d | trials: %d | failed: %d',
            $result->mode,
            $result->objective,
            $result->combinationCount,
            $result->trialCount,
            $result->failedTrialCount,
        ));

        if ($result->bestParams === null) {
            $this->warn('无有效参数组（全部组合失败或指标不可计算）');

            return;
        }

        $this->line(sprintf(
            'best: score=%s params=%s',
            $result->bestScore ?? 'N/A',
            json_encode($result->bestParams, JSON_UNESCAPED_SLASHES) ?: '{}',
        ));

        $this->newLine();
        $this->line('--- Top '.count($result->top).' ---');
        $rank = 1;
        foreach ($result->top as $row) {
            $this->line(sprintf(
                '#%d score=%s params=%s',
                $rank++,
                $row['score'] ?? 'N/A',
                json_encode($row['params'], JSON_UNESCAPED_SLASHES) ?: '{}',
            ));
        }

        if ($result->mode === 'walk_forward' && isset($result->report['fold_details'])) {
            $this->newLine();
            $this->line('--- 分折明细 ---');
            foreach ((array) $result->report['fold_details'] as $fold) {
                $this->line(sprintf(
                    'fold %d: train=%s~%s test=%s~%s in=%s out=%s params=%s',
                    $fold['fold'],
                    $fold['train_from'],
                    $fold['train_to'],
                    $fold['test_from'],
                    $fold['test_to'],
                    $fold['in_sample_score'] ?? 'N/A',
                    $fold['out_of_sample_score'] ?? 'N/A',
                    json_encode($fold['best_params'], JSON_UNESCAPED_SLASHES) ?: '{}',
                ));
            }
        }
    }

    /**
     * 已完成记录 → 结果视图（命令输出用；不做重算）。
     */
    private function resultFromRecord(ParameterOptimization $record): OptimizationResult
    {
        $report = (array) ($record->report ?? []);

        return new OptimizationResult(
            mode: (string) $record->mode,
            objective: (string) $record->objective,
            combinationCount: (int) ($report['combination_count'] ?? 0),
            trialCount: (int) ($report['trial_count'] ?? 0),
            failedTrialCount: (int) ($report['failed_trial_count'] ?? 0),
            bestParams: $record->best_params,
            bestScore: $record->best_score,
            top: (array) ($report['top'] ?? []),
            report: $report,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJson(string $value, string $option): ?array
    {
        if ($value === '') {
            $this->error("{$option} 为必填参数");

            return null;
        }

        if (str_starts_with($value, '@')) {
            $path = substr($value, 1);
            if (! is_file($path)) {
                $path = base_path($path);
            }

            if (! is_file($path)) {
                $this->error("{$option} 文件不存在：{$path}");

                return null;
            }

            $value = (string) file_get_contents($path);
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            $this->error("{$option} 须为 JSON 对象");

            return null;
        }

        return $decoded;
    }

    private function parseUtc(string $value): ?\DateTimeImmutable
    {
        foreach (['Y-m-d H:i:s', 'Y-m-d'] as $format) {
            $time = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($time !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $time;
            }
        }

        return null;
    }
}
