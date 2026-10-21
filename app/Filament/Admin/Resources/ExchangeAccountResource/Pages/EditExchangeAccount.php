<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ExchangeAccountResource\Pages;

use App\Domain\Exchange\ExchangeAccountService;
use App\Filament\Admin\Resources\ExchangeAccountResource;
use App\Models\ExchangeAccount;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditExchangeAccount extends EditRecord
{
    protected static string $resource = ExchangeAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                // 账户被策略实例引用时隐藏删除（DB 外键为 cascade，避免误删实例）
                ->visible(fn (ExchangeAccount $record): bool => ! $record->strategyInstances()->exists()),
        ];
    }

    /**
     * 走领域服务落库：密钥字段留空时保留原密文，填了才重新加密。
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(ExchangeAccountService::class)->update($record, $data);
    }
}
