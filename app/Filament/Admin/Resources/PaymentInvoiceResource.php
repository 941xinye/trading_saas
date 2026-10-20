<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\PaymentInvoiceResource\Pages\ListPaymentInvoices;
use App\Models\PaymentInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * 租户管理 - 支付单 Resource（spec 007 / FR-009 / FR-010 / FR-014）。
 *
 * 只读：仅 index 路由（无 create/edit），policy 仅 viewAny/view，
 * create/update/delete 由 Gate 缺省拒绝；无任何退款动作/路径（FR-014 反向断言：
 * 退款人工线下处理，平台侧不提供入口）。
 *
 * 租户隔离：PaymentInvoice 挂 BelongsToTenant，TenantScope 自动过滤（SC-006）。
 */
class PaymentInvoiceResource extends Resource
{
    protected static ?string $model = PaymentInvoice::class;

    protected static ?string $slug = 'payment-invoices';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static UnitEnum|string|null $navigationGroup = '计费';

    protected static ?string $navigationLabel = '支付单';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('ref')->label('单号')->searchable(),
                TextColumn::make('plan_code')->label('套餐')->badge(),
                TextColumn::make('amount_usdt')->label('金额 (USDT)'),
                TextColumn::make('paid_amount_usdt')->label('已付 (USDT)')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => ['pending', 'processing', 'partial'],
                        'success' => 'confirmed',
                        'gray' => ['expired', 'manual'],
                    ])
                    ->label('状态'),
                TextColumn::make('expires_at')->dateTime('Y-m-d H:i:s', 'UTC')->label('过期时间 (UTC)'),
                TextColumn::make('confirmed_at')->dateTime('Y-m-d H:i:s', 'UTC')->label('确认时间 (UTC)')->placeholder('—'),
                TextColumn::make('created_at')->dateTime('Y-m-d H:i:s', 'UTC')->label('创建时间 (UTC)')->sortable(),
            ])
            // 只读：无行内动作、无批量动作
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentInvoices::route('/'),
        ];
    }
}
