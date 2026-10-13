<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\StrategyInstanceResource\Pages;

use App\Filament\Admin\Resources\StrategyInstanceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStrategyInstance extends EditRecord
{
    protected static string $resource = StrategyInstanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
