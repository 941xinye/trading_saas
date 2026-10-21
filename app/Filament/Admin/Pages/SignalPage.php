<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Signal\Exceptions\SignalExecutionException;
use App\Domain\Signal\SignalExecutionService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Signal;
use App\Models\StrategyInstance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * 租户信号页：最近信号列表 + 人工确认的一键下单。
 *
 * 执行走 SignalExecutionService（同 API 的校验与幂等），仅 running 实例、
 * 同交易对、信号未过期；同一信号在同一实例上只能成功执行一次。
 */
class SignalPage extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-bolt';

    protected static UnitEnum|string|null $navigationGroup = '策略';

    protected static ?string $navigationLabel = '信号';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'signals';

    protected string $view = 'filament.admin.pages.signals';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('executeSignal')
                ->label('执行信号')
                ->icon('heroicon-o-bolt')
                ->color('warning')
                ->modalHeading('执行信号（市价单）')
                ->modalDescription('人工确认后按信号方向市价下单；仅运行中实例，同一信号同一实例只能执行一次。')
                ->modalSubmitActionLabel('确认下单')
                ->schema([
                    Select::make('signal_id')
                        ->label('信号')
                        ->options(fn (): array => $this->signalOptions())
                        ->searchable()
                        ->required()
                        ->live(),
                    Select::make('instance_id')
                        ->label('运行中实例')
                        ->options(fn (Get $get): array => $this->instanceOptions($get('signal_id')))
                        ->required(),
                    TextInput::make('notional')
                        ->label('名义额 (USDT)')
                        ->numeric()
                        ->default((string) config('signal.execution.default_notional', '100'))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->executeSignal($data);
                }),
        ];
    }

    /**
     * 最近 100 条 long/short 信号。
     *
     * @return Collection<int, Signal>
     */
    public function getSignalsProperty(): Collection
    {
        return Signal::query()
            ->where('tenant_id', $this->tenantId())
            ->whereIn('direction', ['long', 'short'])
            ->latest('triggered_at')
            ->limit(100)
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function signalOptions(): array
    {
        return Signal::query()
            ->where('tenant_id', $this->tenantId())
            ->whereIn('direction', ['long', 'short'])
            ->latest('triggered_at')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Signal $signal): array => [
                (int) $signal->id => sprintf(
                    '#%d %s %s @%s · %s%% · %s',
                    $signal->id,
                    $signal->symbol,
                    $signal->direction,
                    $signal->entry_price,
                    $signal->confidence,
                    $signal->triggered_at?->format('m-d H:i') ?? '—',
                ),
            ])
            ->all();
    }

    /**
     * running 实例；按所选信号交易对过滤。
     *
     * @return array<int, string>
     */
    public function instanceOptions(mixed $signalId): array
    {
        $query = StrategyInstance::query()
            ->where('tenant_id', $this->tenantId())
            ->where('status', 'running');

        if (is_numeric($signalId)) {
            $symbol = Signal::query()
                ->where('tenant_id', $this->tenantId())
                ->where('id', (int) $signalId)
                ->value('symbol');

            if (is_string($symbol) && $symbol !== '') {
                $query->where('symbol', $symbol);
            }
        }

        return $query->orderBy('id')->get()
            ->mapWithKeys(fn (StrategyInstance $instance): array => [
                (int) $instance->id => sprintf('#%d %s %s', $instance->id, $instance->symbol, $instance->interval),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function executeSignal(array $data): void
    {
        $tenantId = $this->tenantId();

        $signal = Signal::query()
            ->where('tenant_id', $tenantId)
            ->find((int) ($data['signal_id'] ?? 0));
        if ($signal === null) {
            $this->notifyExecutionError('信号不存在或不属于当前租户');

            return;
        }

        $instance = StrategyInstance::query()
            ->where('tenant_id', $tenantId)
            ->find((int) ($data['instance_id'] ?? 0));
        if ($instance === null) {
            $this->notifyExecutionError('实例不存在或不属于当前租户');

            return;
        }

        try {
            $result = app(SignalExecutionService::class)->execute(
                $signal,
                $instance,
                (string) ($data['notional'] ?? config('signal.execution.default_notional', '100')),
            );
        } catch (SignalExecutionException $e) {
            $this->notifyExecutionError($e->getMessage());

            return;
        }

        $order = $result['order'];

        Notification::make()
            ->success()
            ->title('信号下单已提交')
            ->body(sprintf(
                '%s %s %s → %s（实例 #%d）',
                $order->side,
                $order->symbol,
                $order->quantity,
                $order->status,
                $instance->id,
            ))
            ->send();
    }

    private function notifyExecutionError(string $message): void
    {
        Notification::make()
            ->danger()
            ->title('信号执行失败')
            ->body($message)
            ->send();
    }

    private function tenantId(): int
    {
        return TenantContext::requireTenantId();
    }
}
