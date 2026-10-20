<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        {{-- 当前套餐 / 订阅周期 --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
            <h3 class="text-sm font-semibold mb-3">当前套餐</h3>
            <dl class="text-sm space-y-2">
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">套餐</dt>
                    <dd class="font-semibold">{{ $this->summary['plan_name'] }}（{{ $this->summary['plan_code'] }}）</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">月费</dt>
                    <dd class="font-mono">{{ $this->summary['price_monthly'] }} USDT</dd>
                </div>
                @if ($this->summary['subscription_status'] !== null)
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">订阅状态</dt>
                        <dd>
                            <span class="inline-block px-2 py-0.5 rounded text-xs {{ in_array($this->summary['subscription_status'], ['active', 'trialing'], true) ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200' : 'bg-amber-100 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200' }}">
                                {{ $this->summary['subscription_status'] }}
                            </span>
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">周期起</dt>
                        <dd class="font-mono text-xs">{{ $this->summary['started_at']?->setTimezone('UTC')->format('Y-m-d H:i') }} UTC</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">周期止</dt>
                        <dd class="font-mono text-xs">{{ $this->summary['ends_at']?->setTimezone('UTC')->format('Y-m-d H:i') ?? '—' }} UTC</dd>
                    </div>
                @else
                    <p class="text-xs text-gray-500 dark:text-gray-400">当前无订阅记录（免费版可无订阅使用）。</p>
                @endif
            </dl>
            <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-700">
                <a href="{{ $this->invoicesUrl }}" class="text-sm text-primary-600 dark:text-primary-400 hover:underline">
                    查看历史支付单 →
                </a>
            </div>
        </div>

        {{-- 待支付单收款面板（轮询刷新倒计时与状态） --}}
        <div wire:poll.15s>
            @if ($this->pendingInvoice !== null)
                @php($invoice = $this->pendingInvoice)
                <div class="rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-4 shadow-sm">
                    <h3 class="text-sm font-semibold mb-3">
                        待支付：{{ $invoice['plan_code'] }}
                        @unless ($invoice['expired'])
                            <span class="ml-2 text-xs font-normal text-amber-700 dark:text-amber-300">
                                剩余 {{ intdiv($invoice['remaining_seconds'], 60) }} 分 {{ $invoice['remaining_seconds'] % 60 }} 秒
                            </span>
                        @else
                            <span class="ml-2 text-xs font-normal text-red-600 dark:text-red-400">已过期，请重新发起</span>
                        @endunless
                    </h3>
                    <dl class="text-sm space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-gray-500 dark:text-gray-400">应付金额</dt>
                            <dd class="font-mono font-semibold">{{ $invoice['amount_usdt'] }} {{ $invoice['currency'] }}（{{ $invoice['chain'] }}）</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400 shrink-0">收款地址</dt>
                            <dd class="font-mono text-xs break-all text-right">{{ $invoice['pay_address'] ?? '—' }}</dd>
                        </div>
                        @if ($invoice['pay_uri'])
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400 shrink-0">支付链接</dt>
                                <dd class="font-mono text-xs break-all text-right">{{ $invoice['pay_uri'] }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between">
                            <dt class="text-gray-500 dark:text-gray-400">单号</dt>
                            <dd class="font-mono text-xs">{{ $invoice['ref'] }}</dd>
                        </div>
                    </dl>
                    @if ($invoice['qr_data_uri'])
                        <div class="mt-3 flex justify-center">
                            <img src="{{ $invoice['qr_data_uri'] }}" alt="收款二维码" class="w-36 h-36 rounded bg-white p-1">
                        </div>
                    @endif
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        足额到账后系统自动确认并激活/续期套餐，无需人工介入；请勿多付或少付。
                    </p>
                </div>
            @else
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                    <h3 class="text-sm font-semibold mb-2">待支付</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">当前无待支付单。点击右上角「升级套餐」发起购买或续费。</p>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
