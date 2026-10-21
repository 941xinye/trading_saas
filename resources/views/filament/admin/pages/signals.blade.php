<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
        <div class="mb-1 flex items-center justify-between gap-4">
            <h2 class="text-sm font-semibold">最近信号（long / short，最多 100 条）</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">执行窗口 {{ (int) config('signal.execution.ttl_minutes', 15) }} 分钟 · 需 running 实例</span>
        </div>
        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
            执行入口在右上角「执行信号」：人工确认后按所选信号方向市价下单，复用权益/日配额闸门与 client_order_id 幂等。
        </p>

        @if ($this->signals->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">暂无信号。请先在「信号订阅」创建 active 订阅并等待行情分析。</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                        <tr>
                            <th class="py-2 pr-3">触发时间 (UTC)</th>
                            <th class="py-2 pr-3">交易对</th>
                            <th class="py-2 pr-3">周期</th>
                            <th class="py-2 pr-3">方向</th>
                            <th class="py-2 pr-3">入场</th>
                            <th class="py-2 pr-3">止损</th>
                            <th class="py-2 pr-3">目标</th>
                            <th class="py-2 pr-3">置信度</th>
                            <th class="py-2">来源</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->signals as $signal)
                            <tr class="border-b border-gray-50 dark:border-gray-700/50">
                                <td class="py-1.5 pr-3 font-mono text-xs">{{ $signal->triggered_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="py-1.5 pr-3 font-mono">{{ $signal->symbol }}</td>
                                <td class="py-1.5 pr-3 font-mono">{{ $signal->interval }}</td>
                                <td class="py-1.5 pr-3">
                                    @if ($signal->direction === 'long')
                                        <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">long</span>
                                    @else
                                        <span class="text-xs font-medium text-danger-600 dark:text-danger-400">short</span>
                                    @endif
                                </td>
                                <td class="py-1.5 pr-3 font-mono">{{ $signal->entry_price }}</td>
                                <td class="py-1.5 pr-3 font-mono">{{ $signal->stop_loss ?? '—' }}</td>
                                <td class="py-1.5 pr-3 font-mono">{{ $signal->exit_price }}</td>
                                <td class="py-1.5 pr-3 font-mono">{{ $signal->confidence }}%</td>
                                <td class="py-1.5 text-xs text-gray-500 dark:text-gray-400">{{ $signal->source }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
