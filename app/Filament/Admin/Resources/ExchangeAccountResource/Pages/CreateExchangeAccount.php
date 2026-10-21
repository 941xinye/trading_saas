<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ExchangeAccountResource\Pages;

use App\Domain\Exchange\ExchangeAccountService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\ExchangeAccountResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateExchangeAccount extends CreateRecord
{
    protected static string $resource = ExchangeAccountResource::class;

    /**
     * 走领域服务落库：api_key/api_secret/passphrase 是虚拟表单字段，
     * 由服务用租户 DEK 加密后写入 encrypted_* 列，不经 Eloquent 直接赋值。
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(ExchangeAccountService::class)->create(TenantContext::requireTenantId(), $data);
    }
}
