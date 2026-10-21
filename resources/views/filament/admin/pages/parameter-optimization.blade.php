<x-filament-panels::page>
    <div class="space-y-4">
        {{-- 任务列表（10s 轮询进度） --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm" wire:poll.10s>
            <h3 class="text-sm font-semibold mb-3">寻优任务</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                        <tr>
                            <th class="py-2 pr-3">ID</th>
                            <th class="py-2 pr-3">策略</th>
                            <th class="py-2 pr-3">交易对/周期</th>
                            <th class="py-2 pr-3">区间 (UTC)</th>
                            <th class="py-2 pr-3">模式</th>
                            <th class="py-2 pr-3">目标</th>
                            <th class="py-2 pr-3">最优分</th>
                            <th class="py-2 pr-3">进度</th>
                            <th class="py-2 pr-3">状态</th>
                            <th class="py-2">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->optimizations as $optimization)
                            <tr class="border-b border-gray-50 dark:border-gray-700/50">
                                <td class="py-2 pr-3 font-mono">#{{ $optimization->id }}</td>
                                <td class="py-2 pr-3">{{ $optimization->strategy_code }}</td>
                                <td class="py-2 pr-3">{{ $optimization->symbol }} / {{ $optimization->interval }}</td>
                                <td class="py-2 pr-3 font-mono text-xs">
                                    {{ $this->formatUtc($optimization->from_utc) }} ~ {{ $this->formatUtc($optimization->to_utc) }}
                                </td>
                                <td class="py-2 pr-3">{{ $optimization->mode }}</td>
                                <td class="py-2 pr-3">{{ $optimization->objective }}</td>
                                <td class="py-2 pr-3 font-mono">{{ $optimization->best_score ?? '—' }}</td>
                                <td class="py-2 pr-3 font-mono">{{ $optimization->progress_pct }}%</td>
                                <td class="py-2 pr-3">
                                    @php($status = $optimization->status->value)
                                    <span class="inline-block px-2 py-0.5 rounded text-xs
                                        {{ $status === 'completed' ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200' : '' }}
                                        {{ $status === 'running' ? 'bg-sky-100 text-sky-900 dark:bg-sky-900/30 dark:text-sky-200' : '' }}
                                        {{ $status === 'pending' ? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' : '' }}
                                        {{ $status === 'failed' ? 'bg-red-100 text-red-900 dark:bg-red-900/30 dark:text-red-200' : '' }}">
                                        {{ $status }}
                                    </span>
                                </td>
                                <td class="py-2">
                                    <a href="{{ url()->current() }}?optimization={{ $optimization->id }}" class="text-primary-600 dark:text-primary-400 hover:underline">详情</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="py-4 text-sm text-gray-500 dark:text-gray-400">
                                    暂无寻优记录。点击右上角「发起寻优」创建第一条任务。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($this->selectedOptimization !== null)
            @php($optimization = $this->selectedOptimization)
            @php($report = (array) ($optimization->report ?? []))

            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <h3 class="text-sm font-semibold">
                    寻优详情 #{{ $optimization->id }}（{{ $optimization->strategy_code }} · {{ $optimization->symbol }} · {{ $optimization->interval }}）
                </h3>
                @if ($optimization->status->value === 'failed' && $optimization->error_message)
                    <p class="mt-2 text-xs text-red-600 dark:text-red-400">失败原因：{{ $optimization->error_message }}</p>
                @endif
                <div class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3">
                        <div class="text-xs text-gray-500 dark:text-gray-400">最优得分 ({{ $optimization->objective }})</div>
                        <div class="mt-1 font-mono">{{ $optimization->best_score ?? '—' }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3">
                        <div class="text-xs text-gray-500 dark:text-gray-400">组合数 / 试跑数</div>
                        <div class="mt-1 font-mono">{{ $report['combination_count'] ?? '—' }} / {{ $report['trial_count'] ?? '—' }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3">
                        <div class="text-xs text-gray-500 dark:text-gray-400">失败试跑</div>
                        <div class="mt-1 font-mono">{{ $report['failed_trial_count'] ?? '—' }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3">
                        <div class="text-xs text-gray-500 dark:text-gray-400">模式</div>
                        <div class="mt-1 font-mono">{{ $optimization->mode }}{{ $optimization->mode === 'walk_forward' ? ' / '.$optimization->folds.' 折' : '' }}</div>
                    </div>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    固定参数：<span class="font-mono">{{ $this->formatJson($optimization->base_params) }}</span>
                </p>
            </div>

            @if ($optimization->best_params !== null)
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                    <h3 class="text-sm font-semibold mb-3">最优参数</h3>
                    <p class="font-mono text-sm break-all">{{ $this->formatJson($optimization->best_params) }}</p>
                </div>
            @endif

            @if (($report['top'] ?? []) !== [])
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                    <h3 class="text-sm font-semibold mb-3">Top {{ count($report['top']) }} 参数组</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                                <tr>
                                    <th class="py-2 pr-3">#</th>
                                    <th class="py-2 pr-3">得分</th>
                                    <th class="py-2 pr-3">参数</th>
                                    <th class="py-2">绩效指标</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['top'] as $index => $row)
                                    <tr class="border-b border-gray-50 dark:border-gray-700/50">
                                        <td class="py-2 pr-3 font-mono">{{ $index + 1 }}</td>
                                        <td class="py-2 pr-3 font-mono">{{ $row['score'] ?? '—' }}</td>
                                        <td class="py-2 pr-3 font-mono text-xs break-all">{{ $this->formatJson($row['params'] ?? []) }}</td>
                                        <td class="py-2 font-mono text-xs">
                                            @if (isset($row['metrics']))
                                                收益 {{ $row['metrics']['total_return_pct'] ?? '—' }}% ·
                                                回撤 {{ $row['metrics']['max_drawdown_pct'] ?? '—' }}% ·
                                                Sharpe {{ $row['metrics']['sharpe_ratio'] ?? '—' }} ·
                                                交易 {{ $row['metrics']['trade_count'] ?? '—' }} 笔
                                            @else
                                                @php($foldScores = $row['fold_scores'] ?? [])
                                                @foreach ($foldScores as $fold => $score)
                                                    <span class="mr-2">折 {{ (int) $fold + 1 }}: {{ $score }}</span>
                                                @endforeach
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if (($report['fold_details'] ?? []) !== [])
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                    <h3 class="text-sm font-semibold mb-3">分折明细（训练段选参 → 样本外评分）</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                                <tr>
                                    <th class="py-2 pr-3">折</th>
                                    <th class="py-2 pr-3">训练段 (UTC)</th>
                                    <th class="py-2 pr-3">样本外段 (UTC)</th>
                                    <th class="py-2 pr-3">训练最优参数</th>
                                    <th class="py-2 pr-3">训练得分</th>
                                    <th class="py-2">样本外得分</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['fold_details'] as $fold)
                                    <tr class="border-b border-gray-50 dark:border-gray-700/50">
                                        <td class="py-2 pr-3 font-mono">{{ $fold['fold'] }}</td>
                                        <td class="py-2 pr-3 font-mono text-xs">{{ $fold['train_from'] }} ~ {{ $fold['train_to'] }}</td>
                                        <td class="py-2 pr-3 font-mono text-xs">{{ $fold['test_from'] }} ~ {{ $fold['test_to'] }}</td>
                                        <td class="py-2 pr-3 font-mono text-xs break-all">{{ $this->formatJson($fold['best_params'] ?? []) }}</td>
                                        <td class="py-2 pr-3 font-mono">{{ $fold['in_sample_score'] ?? '—' }}</td>
                                        <td class="py-2 font-mono">{{ $fold['out_of_sample_score'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <h3 class="text-sm font-semibold mb-3">逐次试跑（最多 300 条）</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                            <tr>
                                <th class="py-2 pr-3">#</th>
                                <th class="py-2 pr-3">折</th>
                                <th class="py-2 pr-3">段</th>
                                <th class="py-2 pr-3">参数</th>
                                <th class="py-2 pr-3">得分</th>
                                <th class="py-2 pr-3">状态</th>
                                <th class="py-2">错误</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->selectedTrials as $trial)
                                <tr class="border-b border-gray-50 dark:border-gray-700/50">
                                    <td class="py-1.5 pr-3 font-mono">{{ $trial->id }}</td>
                                    <td class="py-1.5 pr-3 font-mono">{{ $trial->fold_index !== null ? $trial->fold_index + 1 : '—' }}</td>
                                    <td class="py-1.5 pr-3">{{ $trial->in_sample ? '训练' : '样本外' }}</td>
                                    <td class="py-1.5 pr-3 font-mono text-xs break-all">{{ $this->formatJson($trial->params) }}</td>
                                    <td class="py-1.5 pr-3 font-mono">{{ $trial->score ?? '—' }}</td>
                                    <td class="py-1.5 pr-3">{{ $trial->status }}</td>
                                    <td class="py-1.5 text-xs text-red-600 dark:text-red-400">{{ $trial->error_message ?? '' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-4 text-sm text-gray-500 dark:text-gray-400">
                                        该任务暂无试跑记录（待执行或已失败）。
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
