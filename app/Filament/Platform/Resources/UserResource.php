<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\UserResource\Pages\CreateUser;
use App\Filament\Platform\Resources\UserResource\Pages\EditUser;
use App\Filament\Platform\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * 平台超管 - 用户管理 Resource。
 *
 * User 模型未挂 BelongsToTenant trait（仅作为关联查询入口）；
 * 平台超管上下文 tenant_id=null，TenantScope 不生效，可看到所有租户的用户。
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

    protected static UnitEnum|string|null $navigationGroup = '平台管理';

    protected static ?string $navigationLabel = '用户';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(120)
                    ->label('用户名'),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(180)
                    ->label('邮箱'),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->label('密码'),
                Select::make('role')
                    ->options([
                        'platform_admin' => 'Platform Admin',
                        'tenant_admin' => 'Tenant Admin',
                        'trader' => 'Trader',
                        'viewer' => 'Viewer',
                    ])
                    ->default('viewer')
                    ->required()
                    ->label('角色'),
                TextInput::make('tenant_id')
                    ->numeric()
                    ->nullable()
                    ->label('租户 ID'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('用户名')->searchable(),
                TextColumn::make('email')->label('邮箱')->searchable(),
                TextColumn::make('role')
                    ->badge()
                    ->colors([
                        'danger' => 'platform_admin',
                        'success' => 'tenant_admin',
                        'info' => 'trader',
                        'gray' => 'viewer',
                    ])
                    ->label('角色'),
                TextColumn::make('tenant_id')->label('租户 ID')->sortable(),
                TextColumn::make('created_at')->dateTime('Y-m-d')->label('创建时间'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
