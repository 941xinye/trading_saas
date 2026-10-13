<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SignalSubscriptionResource\Pages;

use App\Filament\Admin\Resources\SignalSubscriptionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSignalSubscription extends CreateRecord
{
    protected static string $resource = SignalSubscriptionResource::class;
}
