<x-filament-widgets::widget>
    <x-slot name="heading">配额用量</x-slot>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-2">今日计量</h4>
            <div class="space-y-2">
                @foreach ($this->usage['daily'] as $row)
                    <div>
                        <div class="flex justify-between text-sm">
                            <span>{{ $row['label'] }}</span>
                            <span class="font-mono">{{ $row['used'] }} / {{ $row['limit'] }}</span>
                        </div>
                        <div class="h-1.5 rounded bg-gray-200 dark:bg-gray-700 mt-1">
                            <div class="h-1.5 rounded {{ $row['limit'] > 0 && $row['used'] >= $row['limit'] ? 'bg-red-500' : 'bg-emerald-500' }}"
                                 style="width: {{ $row['limit'] > 0 ? min(100, (int) floor($row['used'] * 100 / $row['limit'])) : 0 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div>
            <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-2">资源数</h4>
            <div class="space-y-2">
                @foreach ($this->usage['resources'] as $row)
                    <div>
                        <div class="flex justify-between text-sm">
                            <span>{{ $row['label'] }}</span>
                            <span class="font-mono">{{ $row['used'] }} / {{ $row['limit'] }}</span>
                        </div>
                        <div class="h-1.5 rounded bg-gray-200 dark:bg-gray-700 mt-1">
                            <div class="h-1.5 rounded {{ $row['limit'] > 0 && $row['used'] >= $row['limit'] ? 'bg-red-500' : 'bg-emerald-500' }}"
                                 style="width: {{ $row['limit'] > 0 ? min(100, (int) floor($row['used'] * 100 / $row['limit'])) : 0 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
