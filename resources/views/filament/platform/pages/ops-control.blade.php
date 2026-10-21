<x-filament-panels::page>
    @php($state = $this->killSwitchState)

    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-sm font-semibold">当前状态</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    平台级开关，优先级高于单实例熔断；状态存 Redis <code>platform:kill_switch</code>。
                </p>
            </div>

            @if ($state['unavailable'])
                <span class="rounded-full bg-warning-100 px-3 py-1 text-xs font-medium text-warning-700 dark:bg-warning-500/20 dark:text-warning-400">
                    Redis 不可用
                </span>
            @elseif ($state['active'])
                <span class="rounded-full bg-danger-100 px-3 py-1 text-xs font-medium text-danger-700 dark:bg-danger-500/20 dark:text-danger-400">
                    急停中 · 禁止开新仓
                </span>
            @else
                <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400">
                    正常 · 允许开新仓
                </span>
            @endif
        </div>

        @if ($state['unavailable'])
            <p class="mt-4 text-sm text-warning-600 dark:text-warning-400">
                无法读取急停状态，请先恢复 Redis。此时交易链路本身也已处于降级状态。
            </p>
        @elseif ($state['active'])
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">启用原因</dt>
                    <dd class="mt-1">{{ $state['reason'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">操作人</dt>
                    <dd class="mt-1">{{ $state['actor'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">启用时间</dt>
                    <dd class="mt-1 font-mono text-xs">{{ $state['activated_at'] ?: '—' }}</dd>
                </div>
            </dl>
        @endif
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="mb-3 text-sm font-semibold">影响范围</h2>

        <div class="grid gap-4 text-sm sm:grid-cols-2">
            <div>
                <p class="mb-2 text-xs font-medium text-danger-600 dark:text-danger-400">会被拒绝</p>
                <ul class="list-disc space-y-1 pl-5 text-gray-600 dark:text-gray-300">
                    <li>策略开仓 / 加仓（<code>RiskGuard::check('open')</code>）</li>
                    <li>信号一键下单</li>
                    <li>订阅自动跟单</li>
                </ul>
            </div>
            <div>
                <p class="mb-2 text-xs font-medium text-emerald-600 dark:text-emerald-400">保持放行</p>
                <ul class="list-disc space-y-1 pl-5 text-gray-600 dark:text-gray-300">
                    <li>平仓 / reduceOnly 订单</li>
                    <li>撤单与账实对账</li>
                    <li>已有持仓与在途订单不被强制处理</li>
                </ul>
            </div>
        </div>

        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
            等价命令：<code>php artisan ops:kill-switch on --reason="..." --actor=...</code> ·
            <code>php artisan ops:kill-switch off</code>
        </p>
    </div>
</x-filament-panels::page>
