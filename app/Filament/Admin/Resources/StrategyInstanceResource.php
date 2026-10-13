<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\StrategyInstanceResource\Pages\CreateStrategyInstance;
use App\Filament\Admin\Resources\StrategyInstanceResource\Pages\EditStrategyInstance;
use App\Filament\Admin\Resources\StrategyInstanceResource\Pages\ListStrategyInstances;
use App\Models\StrategyInstance;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\Action;
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
}
