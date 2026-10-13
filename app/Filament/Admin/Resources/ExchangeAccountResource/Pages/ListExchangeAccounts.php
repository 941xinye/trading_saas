<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ExchangeAccountResource\Pages;

use App\Filament\Admin\Resources\ExchangeAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExchangeAccounts extends ListRecords
{
    protected static string $resource = ExchangeAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
