<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\UserResource\Pages;

use App\Filament\Platform\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;
}
