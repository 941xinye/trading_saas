<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentInvoiceResource\Pages;

use App\Filament\Admin\Resources\PaymentInvoiceResource;
use Filament\Resources\Pages\ListRecords;

/**
 * 支付单列表（只读：无 CreateAction，无任何退款入口，FR-014）。
 */
class ListPaymentInvoices extends ListRecords
{
    protected static string $resource = PaymentInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
