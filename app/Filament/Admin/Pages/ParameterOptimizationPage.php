<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Backtest\Contracts\OptimizationStatus;
use App\Domain\Backtest\Dto\BacktestRunRequest;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Dto\OptimizationRequest;
use App\Domain\Backtest\Exceptions\OptimizationNotApplicableException;
use App\Domain\Backtest\Models\ParameterOptimization;
use App\Domain\Backtest\Models\ParameterOptimizationTrial;
use App\Domain\Backtest\Services\OptimizationService;
use App\Domain\Tenancy\TenantContext;
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
 * 租户参数寻优页（P11）。
 *
 * 三块能力：
 *  1. 列表：本租户寻优任务倒序（模式/目标/进度/最优分），10s 轮询
 *  2. 发起：策略 + 区间 + 参数网格（JSON）+ 固定参数 + 目标 → OptimizationService::dispatch
 *  3. 详情：最优参数 / Top N / 分折明细（walk-forward）/ 逐次试跑
 *
 * 全部查询显式带 tenant_id（双保险）；网格白名单/组合上限由 ParameterGrid 校验。
 */
class ParameterOptimizationPage extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static UnitEnum|string|null $navigationGroup = '策略';

    protected static ?string $navigationLabel = '参数寻优';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'parameter-optimization';

    protected string $view = 'filament.admin.pages.parameter-optimization';

    /** 详情区目标任务（?optimization=ID） */
    public ?int $selectedOptimizationId = null;

    public function mount(): void
    {
        $id = request()->query('optimization');
        $this->selectedOptimizationId = is_numeric($id) ? (int) $id : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('optimize')
                ->label('发起寻优')
                ->icon('heroicon-o-play')
                ->modalHeading('发起参数寻优')
                ->modalSubmitActionLabel('创建并进入队列')
                ->schema([
                    Select::make('strategy_code')
                        ->label('策略')
                        ->options($this->strategyOptions())
                        ->default('spot_grid')
                        ->required(),
                    TextInput::make('symbol')
                        ->label('交易对')
                        ->default('BTCUSDT')
                        ->maxLength(20)
                        ->required(),
                    Select::make('interval')
                        ->label('K 线周期')
                        ->options(array_combine(
                            BacktestRunRequest::INTERVALS,
                            BacktestRunRequest::INTERVALS,
                        ))
                        ->default('1m')
                        ->required(),
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
                    Select::make('mode')
                        ->label('模式')
                        ->options([
                            'grid' => '网格搜索（全区间）',
                            'walk_forward' => 'Walk-forward（分折样本外）',
                        ])
                        ->default('grid')
                        ->required()
                        ->live(),
                    TextInput::make('folds')
                        ->label('折数')
                        ->numeric()
                        ->default((string) config('optimization.default_folds'))
                        ->helperText('区间切为 folds+1 段，前段训练、后段样本外')
                        ->visible(fn (Get $get): bool => $get('mode') === 'walk_forward')
                        ->required(fn (Get $get): bool => $get('mode') === 'walk_forward'),
                    Select::make('objective')
                        ->label('寻优目标')
                        ->options((array) config('optimization.objectives'))
                        ->default('total_return_pct')
                        ->required(),
                    TextInput::make('top_n')
                        ->label('Top N')
                        ->numeric()
                        ->default((string) config('optimization.top_n'))
                        ->required(),
                    Textarea::make('grid')
                        ->label('参数网格 (JSON)')
                        ->helperText('显式值列表或 min/max/step，如 {"grid_count":[5,10],"lower_price":{"min":90,"max":100,"step":5}}')
                        ->rows(4)
                        ->columnSpanFull()
                        ->required(),
                    Textarea::make('base_params')
                        ->label('固定参数 (JSON，可选)')
                        ->helperText('不参与寻优，如 {"lower_price":"90","upper_price":"110","investment_amount":"1000"}')
                        ->rows(3)
                        ->columnSpanFull(),
                    TextInput::make('initial_capital')
                        ->label('初始资金 (USDT)')
                        ->numeric()
                        ->default((string) config('backtest.defaults.initial_capital'))
                        ->required(),
                    TextInput::make('fee_rate')
                        ->label('手续费率')
                        ->numeric()
                        ->default((string) config('backtest.defaults.fee_rate'))
                        ->required(),
                    TextInput::make('slippage_bps')
                        ->label('滑点 (bps)')
                        ->numeric()
                        ->default((string) config('backtest.defaults.slippage_bps'))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->createAndDispatch($data);
                }),
            Action::make('apply')
                ->label('应用到实例')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->visible(fn (): bool => $this->canApply())
                ->modalHeading('应用最优参数到实例')
                ->modalDescription('最优参数与固定参数合并写入实例 params（保留实例其他参数）；实例须为 stopped。')
                ->modalSubmitActionLabel('应用')
                ->schema([
                    Select::make('instance_id')
                        ->label('目标实例')
                        ->options(fn (): array => $this->applicableInstanceOptions())
                        ->helperText('仅列同策略 / 同交易对 / 同周期且状态为 stopped 的实例')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->applyOptimizationToInstance((int) $data['instance_id']);
                }),
        ];
    }

    // ------------------------------------------------------------------
    // 数据（blade 只读访问器）
    // ------------------------------------------------------------------

    /**
     * @return Collection<int, ParameterOptimization>
     */
    public function getOptimizationsProperty(): Collection
    {
        return ParameterOptimization::query()
            ->where('tenant_id', $this->tenantId())
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    public function getSelectedOptimizationProperty(): ?ParameterOptimization
    {
        if ($this->selectedOptimizationId === null) {
            return null;
        }

        return ParameterOptimization::query()
            ->where('tenant_id', $this->tenantId())
            ->find($this->selectedOptimizationId);
    }

    /**
     * @return Collection<int, ParameterOptimizationTrial>
     */
    public function getSelectedTrialsProperty(): Collection
    {
        if ($this->selectedOptimizationId === null) {
            return collect();
        }

        return ParameterOptimizationTrial::query()
            ->where('tenant_id', $this->tenantId())
            ->where('optimization_id', $this->selectedOptimizationId)
            ->orderBy('id')
            ->limit(300)
            ->get();
    }

    /**
     * 仅列 config/optimization.php 白名单内的策略。
     *
     * @return array<string, string>
     */
    private function strategyOptions(): array
    {
        $labels = [
            'spot_grid' => '现货网格',
            'futures_grid' => '合约网格',
            'spot_dca' => '现货定投',
        ];

        $options = [];
        foreach (array_keys((array) config('optimization.strategies', [])) as $code) {
            $options[(string) $code] = $labels[(string) $code] ?? (string) $code;
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
        $grid = $this->decodeJson((string) ($data['grid'] ?? ''));
        if ($grid === null) {
            $this->notifyError('参数网格须为合法 JSON 对象');

            return;
        }

        $baseParams = [];
        $rawBase = trim((string) ($data['base_params'] ?? ''));
        if ($rawBase !== '') {
            $decoded = $this->decodeJson($rawBase);
            if ($decoded === null) {
                $this->notifyError('固定参数须为合法 JSON 对象');

                return;
            }
            $baseParams = $decoded;
        }

        try {
            $assumptions = new MatchingAssumptions(
                feeRate: (string) $data['fee_rate'],
                slippageBps: (int) $data['slippage_bps'],
                initialCapital: (string) $data['initial_capital'],
            );

            $request = new OptimizationRequest(
                tenantId: $this->tenantId(),
                strategyCode: (string) $data['strategy_code'],
                symbol: (string) $data['symbol'],
                interval: (string) $data['interval'],
                fromUtc: $this->toUtc($data['from_utc']),
                toUtc: $this->toUtc($data['to_utc']),
                grid: $grid,
                mode: (string) $data['mode'],
                folds: (int) ($data['folds'] ?? config('optimization.default_folds')),
                objective: (string) $data['objective'],
                assumptions: $assumptions,
                baseParams: $baseParams,
                topN: (int) $data['top_n'],
            );

            $record = app(OptimizationService::class)->dispatch($request);
            $this->selectedOptimizationId = $record->id;

            Notification::make()
                ->success()
                ->title('寻优任务已创建')
                ->body('任务已进入 backtest 队列，进度每 10 秒自动刷新')
                ->send();
        } catch (\InvalidArgumentException $e) {
            $this->notifyError('发起寻优失败：'.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 应用到实例（回测 → 寻优 → 实盘闭环）
    // ------------------------------------------------------------------

    /**
     * 当前详情任务是否可应用：completed 且含最优参数。
     */
    public function canApply(): bool
    {
        $optimization = $this->selectedOptimization;

        return $optimization !== null
            && $optimization->status === OptimizationStatus::Completed
            && ! empty($optimization->best_params);
    }

    /**
     * 可应用的目标实例：同租户 / 同策略 / 同交易对 / 同周期且 stopped。
     *
     * @return array<int, string>
     */
    public function applicableInstanceOptions(): array
    {
        $optimization = $this->selectedOptimization;
        if ($optimization === null) {
            return [];
        }

        return StrategyInstance::query()
            ->where('tenant_id', $this->tenantId())
            ->where('symbol', $optimization->symbol)
            ->where('interval', $optimization->interval)
            ->where('status', 'stopped')
            ->whereHas('strategy', fn ($query) => $query->where('code', $optimization->strategy_code))
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (StrategyInstance $instance): array => [
                (int) $instance->id => sprintf('#%d %s/%s', $instance->id, $instance->symbol, $instance->interval),
            ])
            ->all();
    }

    private function applyOptimizationToInstance(int $instanceId): void
    {
        $optimization = $this->selectedOptimization;
        if ($optimization === null) {
            $this->notifyError('请先选择寻优任务');

            return;
        }

        $instance = StrategyInstance::query()
            ->where('tenant_id', $this->tenantId())
            ->find($instanceId);

        if ($instance === null) {
            $this->notifyError('目标实例不存在或不属于当前租户');

            return;
        }

        try {
            app(OptimizationService::class)->applyToInstance($optimization, $instance);
        } catch (OptimizationNotApplicableException $e) {
            $this->notifyError($e->getMessage());

            return;
        }

        Notification::make()
            ->success()
            ->title('最优参数已应用到实例 #'.$instance->id)
            ->send();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $value): ?array
    {
        $decoded = json_decode($value, true);

        if (! is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function toUtc(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'));
        }

        return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }

    private function notifyError(string $message): void
    {
        Notification::make()
            ->danger()
            ->title($message)
            ->send();
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

    public function formatJson(mixed $value): string
    {
        return is_array($value) && $value !== []
            ? (json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')
            : '—';
    }
}
