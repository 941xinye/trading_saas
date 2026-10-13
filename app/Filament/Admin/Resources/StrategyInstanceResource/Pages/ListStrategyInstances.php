<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\StrategyInstanceResource\Pages;

use App\Filament\Admin\Resources\StrategyInstanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStrategyInstances extends ListRecords
{
    protected static string $resource = StrategyInstanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
