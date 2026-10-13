<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SignalSubscriptionResource\Pages;

use App\Filament\Admin\Resources\SignalSubscriptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSignalSubscriptions extends ListRecords
{
    protected static string $resource = SignalSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
