<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\ExchangeResource\Pages;

use App\Filament\Platform\Resources\ExchangeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExchange extends EditRecord
{
    protected static string $resource = ExchangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
