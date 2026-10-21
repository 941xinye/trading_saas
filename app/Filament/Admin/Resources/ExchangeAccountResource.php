<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ExchangeAccountResource\Pages\CreateExchangeAccount;
use App\Filament\Admin\Resources\ExchangeAccountResource\Pages\EditExchangeAccount;
use App\Filament\Admin\Resources\ExchangeAccountResource\Pages\ListExchangeAccounts;
use App\Models\ExchangeAccount;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * 租户管理 - 交易所账户 Resource。
 *
 * ExchangeAccount 模型挂 BelongsToTenant trait，Admin 面板的 BootstrapTenancy 中间件
 * 初始化 TenantContext 后，TenantScope 自动按当前租户过滤。
 *
 * 敏感字段（api_key / api_secret / passphrase）为虚拟表单字段，不直接写模型；
 * 创建/编辑页均走 ExchangeAccountService，用租户 DEK 加密后落 encrypted_* 列。
 */
class ExchangeAccountResource extends Resource
{
    protected static ?string $model = ExchangeAccount::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-key';

    protected static UnitEnum|string|null $navigationGroup = '交易所';

    protected static ?string $navigationLabel = '交易所账户';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('exchange_id')
                    ->relationship('exchange', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->label('交易所'),
                TextInput::make('label')
                    ->required()
                    ->maxLength(60)
                    ->label('账户标签'),
                Select::make('market')
                    ->options([
                        'spot' => 'Spot',
                        'futures' => 'Futures',
                    ])
                    ->default('spot')
                    ->required()
                    ->label('市场'),
                TextInput::make('sub_account')
                    ->maxLength(60)
                    ->nullable()
                    ->label('子账户'),
                TextInput::make('api_key')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('编辑时留空表示保持原密钥不变；保存后仅存密文，不回显。')
                    ->label('API Key'),
                TextInput::make('api_secret')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('编辑时留空表示保持原密钥不变；保存后仅存密文，不回显。')
                    ->label('API Secret'),
                TextInput::make('passphrase')
                    ->password()
                    ->revealable()
                    ->nullable()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->label('Passphrase (OKX)'),
                Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'revoked' => 'Revoked',
                        'expired' => 'Expired',
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
                TextColumn::make('exchange.name')->label('交易所'),
                TextColumn::make('label')->label('标签')->searchable(),
                TextColumn::make('market')->badge()->label('市场'),
                TextColumn::make('sub_account')->label('子账户')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => 'active',
                        'danger' => 'revoked',
                        'gray' => 'expired',
                    ])
                    ->label('状态'),
                TextColumn::make('last_synced_at')->dateTime('Y-m-d H:i')->label('最近同步'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExchangeAccounts::route('/'),
            'create' => CreateExchangeAccount::route('/create'),
            'edit' => EditExchangeAccount::route('/{record}/edit'),
        ];
    }
}
