<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Backtest\Contracts\BacktestRunStatus;
use App\Domain\Backtest\Dto\BacktestRunRequest;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Exceptions\BacktestConcurrencyException;
use App\Domain\Backtest\Jobs\RunBacktestJob;
use App\Domain\Backtest\Models\BacktestReport;
use App\Domain\Backtest\Models\BacktestRun;
use App\Domain\Backtest\Models\BacktestSimulatedTrade;
use App\Domain\Backtest\Services\BacktestRunService;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExchangeAccount;
use App\Models\Strategy;
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
            Action::make('createInstance')
                ->label('落地实盘')
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->visible(fn (): bool => $this->canCreateInstance())
                ->modalHeading('从回测创建策略实例')
                ->modalDescription('以回测的参数快照 / 交易对 / 周期预填实例，保存为 stopped，需手动启动。')
                ->modalSubmitActionLabel('创建实例')
                ->schema([
                    Select::make('exchange_account_id')
                        ->label('交易所账户')
                        ->options(fn (): array => $this->accountOptions())
                        ->required(),
                    TextInput::make('allocated_capital')
                        ->label('分配资金 (USDT)')
                        ->numeric()
                        ->default(fn (): ?string => $this->defaultCapital())
                        ->required(),
                    TextInput::make('leverage')
                        ->label('杠杆（合约策略可选）')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(125)
                        ->nullable(),
                ])
                ->action(function (array $data): void {
                    $this->createInstanceFromRun($data);
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
     * @param  array<string, mixed>  $data
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
     * @param  array<string, mixed>  $data
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

    // ------------------------------------------------------------------
    // 落地实盘（回测 → 策略实例）
    // ------------------------------------------------------------------

    /**
     * 详情任务是否为可落地的已完成回测。
     */
    public function canCreateInstance(): bool
    {
        return $this->selectedRun?->status === BacktestRunStatus::Completed;
    }

    /**
     * 本租户交易所账户下拉选项。
     *
     * @return array<int, string>
     */
    public function accountOptions(): array
    {
        return ExchangeAccount::query()
            ->where('tenant_id', $this->tenantId())
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (ExchangeAccount $account): array => [
                (int) $account->id => sprintf('#%d %s (%s)', $account->id, $account->label, $account->market),
            ])
            ->all();
    }

    /**
     * 分配资金默认取回测初始资金快照。
     */
    public function defaultCapital(): ?string
    {
        $capital = $this->selectedRun?->assumptions['initial_capital'] ?? null;

        return $capital !== null ? (string) $capital : null;
    }

    /**
     * 以回测快照创建 stopped 策略实例（不自动启动）。
     *
     * @param  array<string, mixed>  $data
     */
    private function createInstanceFromRun(array $data): void
    {
        $run = $this->selectedRun;
        if ($run === null) {
            $this->notifyInstanceError('请先选择回测任务');

            return;
        }

        if ($run->status !== BacktestRunStatus::Completed) {
            $this->notifyInstanceError('仅已完成的回测可以落地实盘');

            return;
        }

        $strategy = Strategy::query()->where('code', (string) $run->strategy_code)->first();
        if ($strategy === null) {
            $this->notifyInstanceError(sprintf('未找到策略定义 %s，请先初始化策略表', $run->strategy_code));

            return;
        }

        $account = ExchangeAccount::query()
            ->where('tenant_id', $this->tenantId())
            ->find((int) ($data['exchange_account_id'] ?? 0));
        if ($account === null) {
            $this->notifyInstanceError('交易所账户不存在或不属于当前租户');

            return;
        }

        $capital = (string) ($data['allocated_capital'] ?? '');
        if (! is_numeric($capital) || bccomp($capital, '0', 8) <= 0) {
            $this->notifyInstanceError('分配资金必须大于 0');

            return;
        }

        $duplicated = StrategyInstance::query()
            ->where('tenant_id', $this->tenantId())
            ->where('strategy_id', $strategy->id)
            ->where('symbol', $run->symbol)
            ->exists();
        if ($duplicated) {
            $this->notifyInstanceError('同策略同交易对已存在实例，请先调整或删除');

            return;
        }

        $params = (array) ($run->params_snapshot ?? []);
        // 回测快照中的上下文键不属于实例参数
        unset($params['tenant_id'], $params['symbol'], $params['allocated_capital']);

        $leverage = isset($data['leverage']) && $data['leverage'] !== ''
            ? (int) $data['leverage']
            : null;

        $instance = StrategyInstance::query()->create([
            'tenant_id' => $this->tenantId(),
            'strategy_id' => $strategy->id,
            'exchange_account_id' => $account->id,
            'symbol' => (string) $run->symbol,
            'interval' => (string) $run->interval,
            'params' => $params,
            'allocated_capital' => $capital,
            'leverage' => $leverage,
            'status' => 'stopped',
        ]);

        Notification::make()
            ->success()
            ->title(sprintf('策略实例 #%d 已创建', $instance->id))
            ->body(sprintf(
                '%s / %s / %s，已保存为 stopped，可在「策略实例」页启动',
                $strategy->name,
                $instance->symbol,
                $instance->interval,
            ))
            ->send();
    }

    private function notifyInstanceError(string $message): void
    {
        Notification::make()
            ->danger()
            ->title('落地实盘失败')
            ->body($message)
            ->send();
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
