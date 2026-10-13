<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\SignalSubscriptionResource\Pages\CreateSignalSubscription;
use App\Filament\Admin\Resources\SignalSubscriptionResource\Pages\EditSignalSubscription;
use App\Filament\Admin\Resources\SignalSubscriptionResource\Pages\ListSignalSubscriptions;
use App\Models\SignalSubscription;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * 租户管理 - AI 信号订阅 Resource。
 *
 * 字段：name / symbols / intervals / ai_prompter / ai_model / channels / rule_fallback_enabled / active
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
            ]);
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
