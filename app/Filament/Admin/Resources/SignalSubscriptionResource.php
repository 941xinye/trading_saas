<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\SignalSubscriptionResource\Pages\CreateSignalSubscription;
use App\Filament\Admin\Resources\SignalSubscriptionResource\Pages\EditSignalSubscription;
use App\Filament\Admin\Resources\SignalSubscriptionResource\Pages\ListSignalSubscriptions;
use App\Models\SignalSubscription;
use App\Models\StrategyInstance;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * 租户管理 - AI 信号订阅 Resource。
 *
 * 字段：name / symbols / intervals / ai_prompter / ai_model / channels /
 *      rule_fallback_enabled / active + 自动跟单（auto_execute / 执行实例 /
 *      单次名义额 / 最低置信度 / 当日上限）
 */
class SignalSubscriptionResource extends Resource
{
    protected static ?string $model = SignalSubscription::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static UnitEnum|string|null $navigationGroup = '信号';

    protected static ?string $navigationLabel = '信号订阅';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(120)
                    ->label('订阅名称'),
                TextInput::make('symbols')
                    ->placeholder('BTCUSDT,ETHUSDT')
                    ->maxLength(500)
                    ->label('订阅交易对'),
                TextInput::make('intervals')
                    ->placeholder('1m,5m,15m,1h')
                    ->maxLength(120)
                    ->label('周期'),
                Select::make('ai_prompter')
                    ->options([
                        'openai' => 'OpenAI',
                        'deepseek' => 'DeepSeek',
                        'qwen' => 'Qwen',
                        'local' => 'Local Ollama',
                    ])
                    ->default('deepseek')
                    ->required()
                    ->label('AI Prompter'),
                TextInput::make('ai_model')
                    ->maxLength(80)
                    ->label('AI 模型'),
                TextInput::make('channels')
                    ->placeholder('telegram,websocket')
                    ->maxLength(120)
                    ->label('通知渠道'),
                Select::make('rule_fallback_enabled')
                    ->options([
                        1 => 'Enabled',
                        0 => 'Disabled',
                    ])
                    ->default(1)
                    ->label('规则引擎兜底'),
                Select::make('active')
                    ->options([
                        1 => 'Active',
                        0 => 'Inactive',
                    ])
                    ->default(1)
                    ->label('状态'),
                Select::make('auto_execute')
                    ->options([
                        1 => 'Enabled',
                        0 => 'Disabled',
                    ])
                    ->default(0)
                    ->live()
                    ->label('自动跟单'),
                Select::make('execution_instance_id')
                    ->options(fn (): array => self::executionInstanceOptions())
                    ->searchable()
                    ->visible(fn (Get $get): bool => (int) $get('auto_execute') === 1)
                    ->required(fn (Get $get): bool => (int) $get('auto_execute') === 1)
                    ->label('执行实例')
                    ->helperText('自动跟单只把信号下到该实例；实例须处于 running，且交易对与信号一致。'),
                TextInput::make('execution_notional')
                    ->numeric()
                    ->minValue(0)
                    ->placeholder((string) config('signal.execution.default_notional', '100'))
                    ->visible(fn (Get $get): bool => (int) $get('auto_execute') === 1)
                    ->label('单次名义额 (USDT)')
                    ->helperText('留空使用默认值；不超过实例分配资金与全局上限。'),
                TextInput::make('execution_min_confidence')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->default(0)
                    ->visible(fn (Get $get): bool => (int) $get('auto_execute') === 1)
                    ->label('最低置信度')
                    ->helperText('0 = 不按置信度过滤。'),
                TextInput::make('execution_daily_limit')
                    ->numeric()
                    ->minValue(0)
                    ->default(20)
                    ->visible(fn (Get $get): bool => (int) $get('auto_execute') === 1)
                    ->label('当日信号单上限')
                    ->helperText('0 = 不限；按该实例当日已受理的 sig_ 信号单计数（人工 + 自动）。'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('订阅名称')->searchable(),
                TextColumn::make('symbols')->limit(40)->label('交易对'),
                TextColumn::make('ai_prompter')->badge()->label('AI Provider'),
                TextColumn::make('ai_model')->label('模型'),
                TextColumn::make('channels')->label('渠道'),
                TextColumn::make('rule_fallback_enabled')
                    ->badge()
                    ->colors([
                        'success' => 1,
                        'gray' => 0,
                    ])
                    ->label('兜底'),
                TextColumn::make('active')
                    ->badge()
                    ->colors([
                        'success' => 1,
                        'gray' => 0,
                    ])
                    ->label('状态'),
                TextColumn::make('auto_execute')
                    ->badge()
                    ->colors([
                        'warning' => 1,
                        'gray' => 0,
                    ])
                    ->label('自动跟单'),
                TextColumn::make('execution_instance_id')
                    ->label('执行实例')
                    ->placeholder('—'),
            ]);
    }

    /**
     * 自动跟单可绑定的策略实例（实例归属由租户全局作用域隔离）。
     *
     * @return array<int, string>
     */
    private static function executionInstanceOptions(): array
    {
        return StrategyInstance::query()
            ->orderBy('id')
            ->get(['id', 'symbol', 'interval', 'status'])
            ->mapWithKeys(fn (StrategyInstance $instance): array => [
                (int) $instance->id => sprintf(
                    '#%d %s %s · %s',
                    (int) $instance->id,
                    (string) $instance->symbol,
                    (string) $instance->interval,
                    (string) $instance->status,
                ),
            ])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSignalSubscriptions::route('/'),
            'create' => CreateSignalSubscription::route('/create'),
            'edit' => EditSignalSubscription::route('/{record}/edit'),
        ];
    }
}
