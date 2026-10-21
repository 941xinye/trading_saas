<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Backtest\Dto\BacktestRunRequest;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Exceptions\BacktestConcurrencyException;
use App\Domain\Backtest\Jobs\RunBacktestJob;
use App\Domain\Backtest\Models\BacktestReport;
use App\Domain\Backtest\Models\BacktestRun;
use App\Domain\Backtest\Models\BacktestSimulatedTrade;
use App\Domain\Backtest\Services\BacktestRunService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Widgets\EquityCurveWidget;
use App\Models\StrategyInstance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * 租户回测绩效页（spec 008 / US5 / T043）。
 *
 * 三块能力：
 *  1. 列表：本租户回测任务倒序（状态/策略/区间/收益率/进度），10s 轮询
 *  2. 发起：实例或 inline DSL + 区间 + 撮合假设 → BacktestRunService::create → dispatch Job
 *  3. 详情：净值曲线 / 指标卡 / 假设区 / 交易明细 四区（读持久化报告，零重算，SC-003）
 *
 * 全部查询显式带 tenant_id（双保险，SC-006）；策略白名单/区间上限由服务层校验。
 */
class BacktestPerformancePage extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static UnitEnum|string|null $navigationGroup = '策略';

    protected static ?string $navigationLabel = '回测绩效';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'backtest-performance';

    protected string $view = 'filament.admin.pages.backtest-performance';

    /** 详情区目标任务（?run=ID） */
    public ?int $selectedRunId = null;

    public function mount(): void
    {
        $runId = request()->query('run');
        $this->selectedRunId = is_numeric($runId) ? (int) $runId : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runBacktest')
                ->label('发起回测')
                ->icon('heroicon-o-play')
                ->modalHeading('发起回测')
                ->modalSubmitActionLabel('创建并进入队列')
                ->schema([
                    Select::make('source')
                        ->label('回测来源')
                        ->options([
                            'instance' => '策略实例',
                            'inline_dsl' => 'inline DSL',
                        ])
                        ->default('instance')
                        ->required()
                        ->live(),
                    Select::make('strategy_instance_id')
                        ->label('策略实例')
                        ->options(fn (): array => $this->instanceOptions())
                        ->searchable()
                        ->required(fn (Get $get): bool => $get('source') === 'instance')
                        ->visible(fn (Get $get): bool => $get('source') === 'instance'),
                    TextInput::make('symbol')
                        ->label('交易对')
                        ->default('BTCUSDT')
                        ->maxLength(30)
                        ->visible(fn (Get $get): bool => $get('source') === 'inline_dsl'),
                    Select::make('interval')
                        ->label('K 线周期')
                        ->options(array_combine(
                            BacktestRunRequest::INTERVALS,
                            BacktestRunRequest::INTERVALS,
                        ))
                        ->default('1m')
                        ->required(fn (Get $get): bool => $get('source') === 'inline_dsl')
                        ->visible(fn (Get $get): bool => $get('source') === 'inline_dsl'),
                    Textarea::make('dsl_script')
                        ->label('DSL 脚本 (YAML)')
                        ->helperText('仅 custom_dsl；filter/entry/exit/risk 四段完整脚本，随任务快照执行。')
                        ->rows(12)
                        ->columnSpanFull()
                        ->required(fn (Get $get): bool => $get('source') === 'inline_dsl')
                        ->visible(fn (Get $get): bool => $get('source') === 'inline_dsl'),
                    DateTimePicker::make('from_utc')
                        ->label('区间开始 (UTC)')
                        ->timezone('UTC')
                        ->seconds(false)
                        ->required(),
                    DateTimePicker::make('to_utc')
                        ->label('区间结束 (UTC)')
                        ->timezone('UTC')
                        ->seconds(false)
                        ->required(),
                    TextInput::make('initial_capital')
                        ->label('初始资金 (USDT)')
                        ->numeric()
                        ->default((string) config('backtest.defaults.initial_capital'))
                        ->required(),
                    TextInput::make('fee_rate')
                        ->label('手续费率')
                        ->numeric()
                        ->default((string) config('backtest.defaults.fee_rate'))
                        ->helperText('默认 0.001（Binance 现货 taker 0.1%），上限 '.config('backtest.limits.fee_rate_max'))
                        ->required(),
                    TextInput::make('slippage_bps')
                        ->label('滑点 (bps)')
                        ->numeric()
                        ->default((string) config('backtest.defaults.slippage_bps'))
                        ->helperText('上限 '.config('backtest.limits.slippage_bps_max'))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->createAndDispatch($data);
                }),
        ];
    }

    // ------------------------------------------------------------------
    // 数据（blade 只读访问器）
    // ------------------------------------------------------------------

    /**
     * @return Collection<int, BacktestRun>
     */
    public function getRunsProperty(): Collection
    {
        return BacktestRun::query()
            ->where('tenant_id', $this->tenantId())
            ->with('report')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    public function getSelectedRunProperty(): ?BacktestRun
    {
        if ($this->selectedRunId === null) {
            return null;
        }

        return BacktestRun::query()
            ->where('tenant_id', $this->tenantId())
            ->with('report')
            ->find($this->selectedRunId);
    }

    public function getSelectedReportProperty(): ?BacktestReport
    {
        return $this->selectedRun?->report;
    }

    /**
     * @return Collection<int, BacktestSimulatedTrade>
     */
    public function getSelectedTradesProperty(): Collection
    {
        if ($this->selectedRunId === null) {
            return collect();
        }

        return BacktestSimulatedTrade::query()
            ->where('tenant_id', $this->tenantId())
            ->where('backtest_run_id', $this->selectedRunId)
            ->orderBy('seq')
            ->limit(200)
            ->get();
    }

    /**
     * 实例下拉选项（仅本租户；TenantScope + 显式 tenant_id 双保险）。
     *
     * @return array<int, string>
     */
    private function instanceOptions(): array
    {
        $options = [];
        foreach (StrategyInstance::query()
            ->where('tenant_id', $this->tenantId())
            ->with('strategy')
            ->orderBy('id')
            ->get() as $instance) {
            $options[(int) $instance->id] = sprintf(
                '#%d %s %s %s',
                $instance->id,
                (string) ($instance->strategy?->name ?? $instance->strategy?->code ?? '策略'),
                (string) $instance->symbol,
                (string) $instance->interval,
            );
        }

        return $options;
    }

    // ------------------------------------------------------------------
    // 发起
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private function createAndDispatch(array $data): void
    {
        try {
            [$strategyCode, $params, $symbol, $interval] = $this->resolveTarget($data);

            $assumptions = new MatchingAssumptions(
                feeRate: (string) $data['fee_rate'],
                slippageBps: (int) $data['slippage_bps'],
                initialCapital: (string) $data['initial_capital'],
            );

            $run = app(BacktestRunService::class)->create(
                $this->tenantId(),
                $strategyCode,
                $params,
                $symbol,
                $interval,
                $this->toUtc($data['from_utc']),
                $this->toUtc($data['to_utc']),
                $assumptions,
            );

            RunBacktestJob::dispatch($run->id);
            $this->selectedRunId = $run->id;

            Notification::make()
                ->success()
                ->title('回测任务已创建')
                ->body('任务已进入 backtest 队列，进度每 10 秒自动刷新')
                ->send();
        } catch (\InvalidArgumentException|BacktestConcurrencyException $e) {
            Notification::make()
                ->danger()
                ->title('发起回测失败')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: string, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function resolveTarget(array $data): array
    {
        if (($data['source'] ?? 'instance') === 'inline_dsl') {
            $symbol = (string) ($data['symbol'] ?? 'BTCUSDT');
            $interval = (string) ($data['interval'] ?? '1m');

            return [
                'custom_dsl',
                [
                    'dsl_script' => (string) ($data['dsl_script'] ?? ''),
                    'symbol' => $symbol,
                    'tenant_id' => $this->tenantId(),
                    'allocated_capital' => (string) $data['initial_capital'],
                ],
                $symbol,
                $interval,
            ];
        }

        $instance = StrategyInstance::query()
            ->where('tenant_id', $this->tenantId())
            ->with('strategy')
            ->find((int) ($data['strategy_instance_id'] ?? 0));

        if ($instance === null) {
            throw new \InvalidArgumentException('策略实例不存在或不属于当前租户');
        }

        return [
            (string) ($instance->strategy?->code ?? ''),
            (array) ($instance->params ?? []),
            (string) $instance->symbol,
            (string) $instance->interval,
        ];
    }

    private function toUtc(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'));
        }

        return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }

    private function tenantId(): int
    {
        return (int) TenantContext::getTenantId();
    }

    // ------------------------------------------------------------------
    // blade 展示辅助
    // ------------------------------------------------------------------

    public function formatUtc(mixed $value): string
    {
        return $value instanceof \DateTimeInterface
            ? $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i')
            : '—';
    }
}
