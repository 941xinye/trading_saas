<x-filament-widgets::widget>
    <x-slot name="heading">{{ __('大户爆仓密集区') }}</x-slot>
    <x-slot name="actions">
        <select wire:change="$refresh" wire:model="filterSymbol"
                class="border rounded px-2 py-1 text-xs bg-white dark:bg-gray-800">
            @foreach ($symbolOptions as $sym)
                <option value="{{ $sym }}" @selected($filterSymbol === $sym)>{{ $sym }}</option>
            @endforeach
        </select>
        <select wire:change="$refresh" wire:model="filterWindow"
                class="border rounded px-2 py-1 text-xs bg-white dark:bg-gray-800 ml-2">
            @foreach ($windowOptions as $w)
                <option value="{{ $w }}" @selected($filterWindow === $w)>{{ $w }}</option>
            @endforeach
        </select>
    </x-slot>

    <div class="space-y-4">
        {{-- 注释：side=SELL 意味着多头被强平；side=BUY 意味着空头被强平 --}}
        <p class="text-xs text-gray-500 dark:text-gray-400">
            提示：side=SELL 表示多头被强平（用户多单爆仓），side=BUY 表示空头被强平（用户空单爆仓）。
            桶宽约对应典型价格 0.1%。窗口 {{ $filterWindow }}。
        </p>

        @forelse ($this->clusters as $row)
            <div class="border rounded p-2 {{ $row['side'] === 'SELL' ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20' }}">
                <div class="flex justify-between text-sm font-semibold">
                    <span>
                        <span class="inline-block px-2 py-0.5 rounded text-xs mr-2 {{ $row['side'] === 'SELL' ? 'bg-red-200 text-red-900' : 'bg-green-200 text-green-900' }}">
                            {{ $row['side'] }}
                        </span>
                        {{ $row['price_from'] }} ~ {{ $row['price_to'] }}
                    </span>
                    <span>
                        qty: <span class="font-mono">{{ $row['total_qty'] }}</span>
                        · count: <span class="font-mono">{{ $row['event_count'] }}</span>
                    </span>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400 py-4 text-center">
                暂无 {{ $filterSymbol }} 在 {{ $filterWindow }} 窗口的强平密集区数据。
                <br>
                请确认 market:feed 进程已启动且订阅了该 symbol。
            </p>
        @endforelse

        @if (! empty($this->recentEvents))
            <div class="mt-4 pt-4 border-t">
                <h4 class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">最近 5 条强平事件</h4>
                <ul class="text-xs space-y-1">
                    @foreach ($this->recentEvents as $event)
                        <li class="font-mono">
                            [{{ \Carbon\Carbon::parse($event->event_ts)->setTimezone('UTC')->format('Y-m-d H:i:s') }} UTC]
                            <span class="{{ $event->side === 'SELL' ? 'text-red-600' : 'text-green-600' }}">{{ $event->side }}</span>
                            {{ $event->symbol }} @ {{ $event->price }} × {{ $event->qty }}
                            ≈ ${{ number_format((float) $event->value_usd, 2) }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
