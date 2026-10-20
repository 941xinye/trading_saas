<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Strategy\Custom\Dsl\DslDiagnostic;
use App\Domain\Strategy\Custom\Dsl\DslValidator;
use App\Filament\Admin\Resources\StrategyInstanceResource\Pages\CreateStrategyInstance;
use App\Filament\Admin\Resources\StrategyInstanceResource\Pages\EditStrategyInstance;
use App\Filament\Admin\Resources\StrategyInstanceResource\Pages\ListStrategyInstances;
use App\Models\Strategy;
use App\Models\StrategyInstance;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * 租户管理 - 策略实例 Resource。
 *
 * 状态机：stopped → starting → running ↔ paused_by_breaker ↔ error
 * 骨架阶段 start/stop action 占位，POC 阶段调度到 StrategyRunner 协程。
 */
class StrategyInstanceResource extends Resource
{
    protected static ?string $model = StrategyInstance::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static UnitEnum|string|null $navigationGroup = '策略';

    protected static ?string $navigationLabel = '策略实例';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('strategy_id')
                    ->relationship('strategy', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->label('策略'),
                Select::make('exchange_account_id')
                    ->relationship('exchangeAccount', 'label')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->label('交易所账户'),
                TextInput::make('symbol')
                    ->required()
                    ->maxLength(30)
                    ->label('交易对'),
                TextInput::make('interval')
                    ->required()
                    ->maxLength(10)
                    ->label('K 线周期'),
                TextInput::make('allocated_capital')
                    ->numeric()
                    ->required()
                    ->label('分配资金 (USDT)'),
                TextInput::make('leverage')
                    ->numeric()
                    ->nullable()
                    ->label('杠杆'),
                Textarea::make('params.dsl_script')
                    ->label('DSL 脚本 (YAML)')
                    ->helperText('自定义 DSL 策略专用：仅支持受限 YAML 子集（filter/entry/exit/risk 四段）。保存草稿不启动实例，试装载仅编译校验。')
                    ->rows(16)
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => static::isDslStrategy($get('strategy_id')))
                    ->required(fn (Get $get): bool => static::isDslStrategy($get('strategy_id')))
                    ->rule(static::dslScriptRule()),
                Action::make('dslTestLoad')
                    ->label('试装载（仅编译校验）')
                    ->icon('heroicon-o-beaker')
                    ->visible(fn (Get $get): bool => static::isDslStrategy($get('strategy_id')))
                    ->action(function (Get $get, Notification $notification): void {
                        $diagnostics = static::testLoadScript((string) ($get('params.dsl_script') ?? ''));

                        if ($diagnostics === []) {
                            $notification->success('DSL 编译通过（未启动实例）');

                            return;
                        }

                        $notification->danger('DSL 编译失败：'.implode('；', array_map(
                            static::formatDiagnostic(...),
                            array_slice($diagnostics, 0, 3),
                        )));
                    }),
                Select::make('status')
                    ->options([
                        'stopped' => 'Stopped',
                        'starting' => 'Starting',
                        'running' => 'Running',
                        'paused_by_breaker' => 'Paused by Breaker',
                        'error' => 'Error',
                    ])
                    ->default('stopped')
                    ->required()
                    ->label('状态'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('strategy.name')->label('策略'),
                TextColumn::make('exchangeAccount.label')->label('账户'),
                TextColumn::make('symbol')->label('交易对')->searchable(),
                TextColumn::make('interval')->label('周期'),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'stopped',
                        'warning' => 'starting',
                        'success' => 'running',
                        'danger' => ['paused_by_breaker', 'error'],
                    ])
                    ->label('状态'),
                TextColumn::make('allocated_capital')->label('资金'),
                TextColumn::make('unrealized_pnl')->label('未实现盈亏'),
                TextColumn::make('breaker_state')->badge()->label('熔断'),
            ])
            ->actions([
                Action::make('start')
                    ->label('启动')
                    ->icon('heroicon-o-play')
                    ->visible(fn (StrategyInstance $record): bool => $record->status === 'stopped')
                    ->action(function (StrategyInstance $record): void {
                        // 骨架占位：POC 阶段调用 StrategyRunner::start($record)
                        $record->status = 'starting';
                        $record->save();
                    })
                    ->requiresConfirmation(),
                Action::make('stop')
                    ->label('停止')
                    ->icon('heroicon-o-stop')
                    ->color('danger')
                    ->visible(fn (StrategyInstance $record): bool => in_array($record->status, ['running', 'starting', 'paused_by_breaker'], true))
                    ->action(function (StrategyInstance $record): void {
                        // 骨架占位：POC 阶段调用 StrategyRunner::stop($record)
                        $record->status = 'stopped';
                        $record->save();
                    })
                    ->requiresConfirmation(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStrategyInstances::route('/'),
            'create' => CreateStrategyInstance::route('/create'),
            'edit' => EditStrategyInstance::route('/{record}/edit'),
        ];
    }

    // ------------------------------------------------------------------
    // DSL 编辑器（spec 006 WS5 / FR-011/016）
    // ------------------------------------------------------------------

    /**
     * 当前选中的策略是否为自定义 DSL（编辑器可见性/必填判定）。
     */
    public static function isDslStrategy(mixed $strategyId): bool
    {
        if (! is_numeric($strategyId)) {
            return false;
        }

        return Strategy::query()->whereKey((int) $strategyId)->value('code') === 'custom_dsl';
    }

    /**
     * 即时校验规则：仅收集诊断、不启动实例（FR-011 / contracts §8）。
     */
    public static function dslScriptRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $script = is_string($value) ? $value : '';
            if (trim($script) === '') {
                return; // 空脚本交由 required 规则判定
            }

            foreach (app(DslValidator::class)->validate($script) as $diagnostic) {
                if ($diagnostic->level !== DslDiagnostic::LEVEL_ERROR) {
                    continue;
                }

                $fail(static::formatDiagnostic($diagnostic));

                return;
            }
        };
    }

    /**
     * 试装载：仅编译校验，不启动实例（FR-011 / contracts §8）。
     *
     * @return DslDiagnostic[] 空数组 = 编译通过
     */
    public static function testLoadScript(string $script): array
    {
        return app(DslValidator::class)->validate($script);
    }

    public static function formatDiagnostic(DslDiagnostic $diagnostic): string
    {
        return sprintf('第 %d 行 %s：%s', $diagnostic->line, $diagnostic->field, $diagnostic->message);
    }
}
