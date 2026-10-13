<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Platform\Resources\TenantResource\Pages\EditTenant;
use App\Filament\Platform\Resources\TenantResource\Pages\ListTenants;
use App\Models\Tenant;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * 平台超管 - 租户管理 Resource。
 *
 * 注意：Tenant 模型无 BelongsToTenant trait，TenantScope 不生效，可跨租户列表。
 */
class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static UnitEnum|string|null $navigationGroup = '平台管理';

    protected static ?string $navigationLabel = '租户';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(120)
                    ->label('租户名称'),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(60)
                    ->rules(['alpha_dash'])
                    ->label('Slug'),
                Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'suspended' => 'Suspended',
                        'canceled' => 'Canceled',
                    ])
                    ->default('active')
                    ->required()
                    ->label('状态'),
                TextInput::make('plan_code')
                    ->maxLength(30)
                    ->label('套餐'),
                DateTimePicker::make('trial_ends_at')
                    ->nullable()
                    ->label('试用截止'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('租户名称')
                    ->searchable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => 'active',
                        'warning' => 'suspended',
                        'danger' => 'canceled',
                    ])
                    ->label('状态'),
                TextColumn::make('plan_code')
                    ->label('套餐')
                    ->badge(),
                TextColumn::make('trial_ends_at')
                    ->dateTime('Y-m-d H:i')
                    ->label('试用截止'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }
}
