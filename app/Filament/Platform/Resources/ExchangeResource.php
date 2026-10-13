<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\ExchangeResource\Pages\CreateExchange;
use App\Filament\Platform\Resources\ExchangeResource\Pages\EditExchange;
use App\Filament\Platform\Resources\ExchangeResource\Pages\ListExchanges;
use App\Models\Exchange;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * 平台超管 - 交易所配置 Resource。
 *
 * Exchange 模型无 BelongsToTenant trait，平台级共享配置。
 */
class ExchangeResource extends Resource
{
    protected static ?string $model = Exchange::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static UnitEnum|string|null $navigationGroup = '平台管理';

    protected static ?string $navigationLabel = '交易所';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(30)
                    ->rules(['alpha_dash'])
                    ->label('代码'),
                TextInput::make('name')
                    ->required()
                    ->maxLength(120)
                    ->label('名称'),
                TextInput::make('base_url')
                    ->url()
                    ->required()
                    ->label('REST Base URL'),
                TextInput::make('ws_url')
                    ->url()
                    ->nullable()
                    ->label('WebSocket URL'),
                Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'degraded' => 'Degraded',
                        'down' => 'Down',
                    ])
                    ->default('active')
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
                TextColumn::make('code')->label('代码')->searchable(),
                TextColumn::make('name')->label('名称')->searchable(),
                TextColumn::make('base_url')->label('REST URL')->limit(40),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => 'active',
                        'warning' => 'degraded',
                        'danger' => 'down',
                    ])
                    ->label('状态'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExchanges::route('/'),
            'create' => CreateExchange::route('/create'),
            'edit' => EditExchange::route('/{record}/edit'),
        ];
    }
}
