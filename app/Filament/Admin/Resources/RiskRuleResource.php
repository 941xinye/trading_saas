<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\RiskRuleResource\Pages\CreateRiskRule;
use App\Filament\Admin\Resources\RiskRuleResource\Pages\EditRiskRule;
use App\Filament\Admin\Resources\RiskRuleResource\Pages\ListRiskRules;
use App\Models\RiskRule;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * 租户管理 - 风控规则 Resource。
 *
 * rule_type：max_loss_per_trade / max_daily_loss / max_drawdown / consecutive_losses
 * threshold_unit：amount / percent / count
 */
class RiskRuleResource extends Resource
{
    protected static ?string $model = RiskRule::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static UnitEnum|string|null $navigationGroup = '风控';

    protected static ?string $navigationLabel = '风控规则';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('strategy_instance_id')
                    ->numeric()
                    ->nullable()
                    ->label('策略实例 ID（留空为租户级默认）'),
                Select::make('rule_type')
                    ->options([
                        'max_loss_per_trade' => '单笔最大亏损',
                        'max_daily_loss' => '日内最大亏损',
                        'max_drawdown' => '最大回撤',
                        'consecutive_losses' => '连续亏损次数',
                    ])
                    ->required()
                    ->label('规则类型'),
                TextInput::make('threshold_value')
                    ->numeric()
                    ->required()
                    ->label('阈值'),
                Select::make('threshold_unit')
                    ->options([
                        'amount' => 'Amount',
                        'percent' => 'Percent',
                        'count' => 'Count',
                    ])
                    ->required()
                    ->label('阈值单位'),
                TextInput::make('cooldown_minutes')
                    ->numeric()
                    ->default(60)
                    ->label('冷却时间（分钟）'),
                Select::make('auto_recover')
                    ->options([
                        1 => 'Enabled',
                        0 => 'Disabled',
                    ])
                    ->default(1)
                    ->label('自动恢复'),
                Select::make('enabled')
                    ->options([
                        1 => 'Enabled',
                        0 => 'Disabled',
                    ])
                    ->default(1)
                    ->label('启用'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('strategy_instance_id')->label('策略实例')->placeholder('租户级'),
                TextColumn::make('rule_type')->badge()->label('规则'),
                TextColumn::make('threshold_value')->label('阈值'),
                TextColumn::make('threshold_unit')->badge()->label('单位'),
                TextColumn::make('cooldown_minutes')->label('冷却'),
                TextColumn::make('auto_recover')
                    ->badge()
                    ->colors([
                        'success' => 1,
                        'gray' => 0,
                    ])
                    ->label('自动恢复'),
                TextColumn::make('enabled')
                    ->badge()
                    ->colors([
                        'success' => 1,
                        'gray' => 0,
                    ])
                    ->label('启用'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRiskRules::route('/'),
            'create' => CreateRiskRule::route('/create'),
            'edit' => EditRiskRule::route('/{record}/edit'),
        ];
    }
}
