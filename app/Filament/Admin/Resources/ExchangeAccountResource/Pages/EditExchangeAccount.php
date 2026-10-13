<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ExchangeAccountResource\Pages;

use App\Filament\Admin\Resources\ExchangeAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExchangeAccount extends EditRecord
{
    protected static string $resource = ExchangeAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
