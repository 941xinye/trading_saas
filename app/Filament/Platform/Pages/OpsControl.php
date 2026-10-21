<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Domain\Risk\PlatformKillSwitch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;
use UnitEnum;

/**
 * 平台运维：全局急停。
 *
 * 与 `ops:kill-switch` 命令共用同一 Redis 标志与语义：
 * 只停开新仓，平仓 / reduceOnly / 撤单全部放行；不自动撤销在途订单。
 */
class OpsControl extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-power';

    protected static UnitEnum|string|null $navigationGroup = '运维';

    protected static ?string $navigationLabel = '全局急停';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'ops-control';

    protected static ?string $title = '全局急停';

    protected string $view = 'filament.platform.pages.ops-control';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('activateKillSwitch')
                ->label('启用全局急停')
                ->icon('heroicon-o-power')
                ->color('danger')
                ->modalHeading('确认启用全局急停？')
                ->modalDescription('启用后所有策略与信号（含自动跟单）停止开新仓；平仓与 reduceOnly 不受影响，在途订单不会被撤销。')
                ->modalSubmitActionLabel('启用急停')
                ->schema([
                    TextInput::make('reason')
                        ->label('原因')
                        ->required()
                        ->maxLength(200)
                        ->placeholder('如：交易所异常 / 行情剧烈波动 / 例行维护'),
                    TextInput::make('actor')
                        ->label('操作人')
                        ->required()
                        ->maxLength(100)
                        ->default(fn (): string => (string) (auth()->user()?->email ?? auth()->user()?->name ?? 'platform')),
                ])
                ->visible(fn (): bool => ! $this->killSwitchState['active'] && ! $this->killSwitchState['unavailable'])
                ->action(function (array $data): void {
                    $this->activate((string) $data['reason'], (string) $data['actor']);
                }),

            Action::make('deactivateKillSwitch')
                ->label('解除急停')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('确认解除全局急停？')
                ->modalDescription('解除后策略与信号可立即恢复开新仓，请确认风险已排除。')
                ->modalSubmitActionLabel('解除急停')
                ->visible(fn (): bool => $this->killSwitchState['active'] === true)
                ->action(function (): void {
                    $this->deactivate();
                }),
        ];
    }

    /**
     * 页面状态快照；Redis 不可用时显式标记 unavailable，避免误显示为 off。
     *
     * @return array{active:bool,unavailable:bool,reason:?string,actor:?string,activated_at:?string}
     */
    public function getKillSwitchStateProperty(): array
    {
        try {
            $status = app(PlatformKillSwitch::class)->status();
        } catch (Throwable) {
            return [
                'active' => false,
                'unavailable' => true,
                'reason' => null,
                'actor' => null,
                'activated_at' => null,
            ];
        }

        return [
            'active' => $status !== null,
            'unavailable' => false,
            'reason' => $status['reason'] ?? null,
            'actor' => $status['actor'] ?? null,
            'activated_at' => $status['activated_at'] ?? null,
        ];
    }

    private function activate(string $reason, string $actor): void
    {
        try {
            $payload = app(PlatformKillSwitch::class)->activate($reason, $actor);
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('急停启用失败')
                ->body('Redis 不可用：'.$e->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('全局急停已启用')
            ->body(sprintf('原因：%s · 操作人：%s', $payload['reason'], $payload['actor'] ?? '-'))
            ->send();
    }

    private function deactivate(): void
    {
        try {
            app(PlatformKillSwitch::class)->deactivate();
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('解除急停失败')
                ->body('Redis 不可用：'.$e->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('全局急停已解除')
            ->body('策略与信号可恢复开新仓。')
            ->send();
    }
}
