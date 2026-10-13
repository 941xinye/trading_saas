<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RiskRuleResource\Pages;

use App\Filament\Admin\Resources\RiskRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRiskRule extends EditRecord
{
    protected static string $resource = RiskRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
