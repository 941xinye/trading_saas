<x-filament-panels::page>
    <div class="space-y-4">
        {{-- 任务列表（10s 轮询进度） --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm" wire:poll.10s>
            <h3 class="text-sm font-semibold mb-3">回测任务</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                        <tr>
                            <th class="py-2 pr-3">ID</th>
                            <th class="py-2 pr-3">策略</th>
                            <th class="py-2 pr-3">交易对/周期</th>
                            <th class="py-2 pr-3">区间 (UTC)</th>
                            <th class="py-2 pr-3">收益率</th>
                            <th class="py-2 pr-3">进度</th>
                            <th class="py-2 pr-3">状态</th>
                            <th class="py-2">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->runs as $run)
                            <tr class="border-b border-gray-50 dark:border-gray-700/50">
                                <td class="py-2 pr-3 font-mono">#{{ $run->id }}</td>
                                <td class="py-2 pr-3">{{ $run->strategy_code }}</td>
                                <td class="py-2 pr-3">{{ $run->symbol }} / {{ $run->interval }}</td>
                                <td class="py-2 pr-3 font-mono text-xs">
                                    {{ $this->formatUtc($run->from_utc) }} ~ {{ $this->formatUtc($run->to_utc) }}
                                </td>
                                <td class="py-2 pr-3 font-mono">
                                    {{ $run->report?->total_return_pct !== null ? $run->report->total_return_pct.'%' : '—' }}
                                </td>
                                <td class="py-2 pr-3 font-mono">{{ $run->progress_pct }}%</td>
                                <td class="py-2 pr-3">
                                    @php($status = $run->status->value)
                                    <span class="inline-block px-2 py-0.5 rounded text-xs
                                        {{ $status === 'completed' ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200' : '' }}
                                        {{ $status === 'running' ? 'bg-sky-100 text-sky-900 dark:bg-sky-900/30 dark:text-sky-200' : '' }}
                                        {{ $status === 'pending' ? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' : '' }}
                                        {{ $status === 'failed' ? 'bg-red-100 text-red-900 dark:bg-red-900/30 dark:text-red-200' : '' }}">
                                        {{ $status }}
                                    </span>
                                </td>
                                <td class="py-2">
                                    <a href="{{ url()->current() }}?run={{ $run->id }}" class="text-primary-600 dark:text-primary-400 hover:underline">详情</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-4 text-sm text-gray-500 dark:text-gray-400">
                                    暂无回测记录。点击右上角「发起回测」创建第一条任务。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 报告详情四区 --}}
        @if ($this->selectedRun !== null)
            @php($run = $this->selectedRun)
            @php($report = $this->selectedReport)

            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <h3 class="text-sm font-semibold">
                    回测详情 #{{ $run->id }}（{{ $run->strategy_code }} · {{ $run->symbol }} · {{ $run->interval }}）
                </h3>
                @if ($run->status->value === 'failed' && $run->error_message)
                    <p class="mt-2 text-xs text-red-600 dark:text-red-400">失败原因：{{ $run->error_message }}</p>
                @endif
                @if ($report === null)
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">报告尚未产出（当前状态：{{ $run->status->value }}，进度 {{ $run->progress_pct }}%）。</p>
                @endif
            </div>
        @endif

        @if ($this->selectedReport !== null)
            {{-- 1. 净值曲线 --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <h3 class="text-sm font-semibold mb-3">净值曲线</h3>
                @livewire('App\Filament\Admin\Widgets\EquityCurveWidget', ['runId' => $this->selectedRunId])
            </div>

            {{-- 2. 指标卡 --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <h3 class="text-sm font-semibold mb-3">绩效指标</h3>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-sm">
                    @foreach ([
                        ['总收益率', $report->total_return_pct.' %'],
                        ['年化收益率', $report->annualized_return_pct.' %'],
                        ['Sharpe', $report->sharpe_ratio ?? '无交易/零方差'],
                        ['最大回撤', $report->max_drawdown_pct.' %'],
                        ['胜率', $report->win_rate_pct !== null ? $report->win_rate_pct.' %' : '无平仓'],
                        ['盈亏比', $report->profit_factor ?? '无亏损平仓'],
                        ['交易次数', (string) $report->trade_count],
                        ['手续费总额', $report->total_fee.' USDT'],
                        ['回放 bar 数', (string) $report->bar_count],
                        ['数据覆盖率', $report->data_coverage_pct.' %'],
                    ] as [$label, $value])
                        <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3">
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                            <div class="mt-1 font-mono">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    初始资金 {{ $report->initial_capital }} USDT · 期末净值 {{ $report->final_equity }} USDT
                </p>
            </div>

            {{-- 3. 假设区 --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <h3 class="text-sm font-semibold mb-3">撮合假设</h3>
                <dl class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                    <div class="flex justify-between md:block">
                        <dt class="text-gray-500 dark:text-gray-400">手续费率</dt>
                        <dd class="font-mono">{{ $run->assumptions['fee_rate'] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between md:block">
                        <dt class="text-gray-500 dark:text-gray-400">滑点</dt>
                        <dd class="font-mono">{{ $run->assumptions['slippage_bps'] ?? '—' }} bps</dd>
                    </div>
                    <div class="flex justify-between md:block">
                        <dt class="text-gray-500 dark:text-gray-400">初始资金</dt>
                        <dd class="font-mono">{{ $run->assumptions['initial_capital'] ?? '—' }} USDT</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                    口径说明：{{ $run->assumptions['narrative'] ?? '—' }}
                </p>
            </div>

            {{-- 4. 交易明细 --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <h3 class="text-sm font-semibold mb-3">交易明细</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                            <tr>
                                <th class="py-2 pr-3">#</th>
                                <th class="py-2 pr-3">bar 时间 (UTC)</th>
                                <th class="py-2 pr-3">方向</th>
                                <th class="py-2 pr-3">数量</th>
                                <th class="py-2 pr-3">成交价</th>
                                <th class="py-2 pr-3">手续费</th>
                                <th class="py-2">已实现盈亏</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->selectedTrades as $trade)
                                <tr class="border-b border-gray-50 dark:border-gray-700/50">
                                    <td class="py-1.5 pr-3 font-mono">{{ $trade->seq }}</td>
                                    <td class="py-1.5 pr-3 font-mono text-xs">
                                        {{ gmdate('Y-m-d H:i:s', intdiv((int) $trade->bar_open_time, 1000)) }}
                                    </td>
                                    <td class="py-1.5 pr-3">{{ $trade->side }}</td>
                                    <td class="py-1.5 pr-3 font-mono">{{ $trade->qty }}</td>
                                    <td class="py-1.5 pr-3 font-mono">{{ $trade->price }}</td>
                                    <td class="py-1.5 pr-3 font-mono">{{ $trade->fee }}</td>
                                    <td class="py-1.5 font-mono">{{ $trade->realized_pnl }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-4 text-sm text-gray-500 dark:text-gray-400">
                                        区间内无模拟成交（净值恒为初始资金）。
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
