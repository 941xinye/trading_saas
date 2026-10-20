<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backtest\Dto\BacktestRunRequest;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Services\BacktestRunner;
use App\Domain\Backtest\Services\EquityCurveBuilder;
use App\Domain\Backtest\Services\MatchingEngine;
use App\Domain\Backtest\Services\PerformanceCalculator;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * backtest:run 回测命令（spec 008 T025~T026 / contracts/backtest-commands-contract.md §3）。
 *
 * US1 同步直跑（--sync）：当前版本唯一模式；US3 dispatch 模式在后续 WS 实现。
 * Runner 纯内存执行，不写 DB/Redis/不调交易所。
 */
final class BacktestRunCommand extends Command
{
    protected $signature = 'backtest:run
        {strategy : 策略代码（spot_grid|spot_dca|futures_grid|custom_dsl）}
        {symbol : 交易对，如 BTCUSDT}
        {interval : K线周期（1m/5m/15m/1h/4h/1d）}
        {--from= : 起始时间 UTC（Y-m-d H:i:s）}
        {--to= : 结束时间 UTC（Y-m-d H:i:s）}
        {--tenant=1 : 租户 ID}
        {--params= : 策略参数 JSON（如 {"lower_price":"100","upper_price":"120"}）}
        {--capital= : 初始资金（默认 config backtest.defaults.initial_capital）}
        {--fee-rate= : 手续费率（默认 config backtest.defaults.fee_rate）}
        {--slippage= : 滑点 bps（默认 config backtest.defaults.slippage_bps）}
        {--sync : 同步执行（当前唯一模式）}';

    protected $description = '回测指定策略在区间内的表现（US1 同步模式）';

    public function handle(): int
    {
        $strategyCode = (string) $this->argument('strategy');
        $symbol = (string) $this->argument('symbol');
        $interval = (string) $this->argument('interval');
        $tenantId = (int) $this->option('tenant');

        $fromStr = (string) $this->option('from');
        $toStr = (string) $this->option('to');

        if ($fromStr === '' || $toStr === '') {
            $this->error('--from 与 --to 为必填参数');

            return self::INVALID;
        }

        $fromUtc = $this->parseUtc($fromStr);
        $toUtc = $this->parseUtc($toStr);
        if ($fromUtc === null || $toUtc === null) {
            $this->error('时间格式非法，须为 Y-m-d H:i:s（UTC）');

            return self::INVALID;
        }

        if ($fromUtc >= $toUtc) {
            $this->error('--from 必须早于 --to');

            return self::INVALID;
        }

        $params = [];
        if ($this->option('params') !== null) {
            $decoded = json_decode((string) $this->option('params'), true);
            if (! is_array($decoded)) {
                $this->error('--params 须为合法 JSON');

                return self::INVALID;
            }
            $params = $decoded;
        }

        $assumptions = MatchingAssumptions::fromConfig(
            capital: $this->option('capital') !== null ? (string) $this->option('capital') : null,
            feeRate: $this->option('fee-rate') !== null ? (string) $this->option('fee-rate') : null,
            slippageBps: $this->option('slippage') !== null ? (int) $this->option('slippage') : null,
        );

        $request = new BacktestRunRequest(
            tenantId: $tenantId,
            strategyCode: $strategyCode,
            params: $params,
            symbol: $symbol,
            interval: $interval,
            fromUtc: $fromUtc,
            toUtc: $toUtc,
            assumptions: $assumptions,
            runId: 0,
        );

        // 租户上下文恢复（对齐 AiAnalyzeJob 先例）
        TenantContext::initialize($tenantId);
        try {
            $runner = $this->makeRunner($assumptions);
            $result = $runner->run($request);

            $days = max(1, (int) ceil(($toUtc->getTimestamp() - $fromUtc->getTimestamp()) / 86400));
            $report = (new PerformanceCalculator())->calculate($result, $assumptions->initialCapital, $days);

            $this->renderReport($report, $result);
        } catch (Throwable $e) {
            $this->error('回测失败：'.$e->getMessage());

            return self::FAILURE;
        } finally {
            TenantContext::clear();
        }

        return self::SUCCESS;
    }

    private function makeRunner(MatchingAssumptions $assumptions): BacktestRunner
    {
        $historical = app(HistoricalQuery::class);
        $registry = app(StrategyRegistry::class);
        $matcher = new MatchingEngine();
        $equity = new EquityCurveBuilder($assumptions->initialCapital);

        return new BacktestRunner($historical, $registry, $matcher, $equity);
    }

    private function renderReport(\App\Domain\Backtest\Dto\PerformanceReport $report, \App\Domain\Backtest\Dto\BacktestRunResult $result): void
    {
        $this->newLine();
        $this->info('======== 回测报告 ========');
        $this->line(sprintf('barCount: %d | coverage: %s%%', $report->barCount, $report->dataCoveragePct));
        $this->line(sprintf('trades: %d | totalFee: %s', $report->tradeCount, $report->totalFee));
        $this->line(sprintf('initialCapital: %s | finalEquity: %s', $report->initialCapital, $report->finalEquity));
        $this->line(sprintf('totalReturn: %s%% | annualized: %s%%', $report->totalReturnPct, $report->annualizedReturnPct));
        $this->line(sprintf('maxDrawdown: %s%% | sharpe: %s', $report->maxDrawdownPct, $report->sharpeRatio ?? 'N/A'));
        $this->line(sprintf('winRate: %s%% | profitFactor: %s', $report->winRatePct ?? 'N/A', $report->profitFactor ?? 'N/A'));

        if ($result->trades !== []) {
            $this->newLine();
            $this->line('--- 最近 5 笔成交 ---');
            foreach (array_slice($result->trades, -5) as $t) {
                $this->line(sprintf(
                    '#%d %s qty=%s price=%s fee=%s pnl=%s',
                    $t->seq,
                    $t->side,
                    $t->qty,
                    $t->price,
                    $t->fee,
                    $t->realizedPnl,
                ));
            }
        }
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
