<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SignalSubscriptionResource\Pages;

use App\Filament\Admin\Resources\SignalSubscriptionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSignalSubscription extends EditRecord
{
    protected static string $resource = SignalSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
