<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\ExchangeResource\Pages;

use App\Filament\Platform\Resources\ExchangeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExchanges extends ListRecords
{
    protected static string $resource = ExchangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
