<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Domain\Billing\Metering\TwoPhaseMeter;
use App\Domain\Billing\Plans\PlanService;
use App\Domain\Billing\QuotaGuard;
use App\Domain\Tenancy\TenantContext;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * 配额用量看板 Widget（spec 007 / FR-009，契约 billing-integration-contract.md §5）。
 *
 * 口径复用（不另算）：
 *  - 日计量：TwoPhaseMeter::usedTodayOrDb()（Redis 热计数；不可用降级 meter_events
 *    当日 frozen+settled，与 reconcile() 同口径）
 *  - 上限：PlanService::limit()
 *  - 资源数：与 QuotaGuard::assertResourceCount() 调用方统计口径一致
 *    （exchange_accounts / strategy_instances 全量；signal_subscriptions 仅 active=1）
 */
class QuotaUsageWidget extends Widget
{
    protected string $view = 'filament.admin.widgets.quota-usage';

    // 仅内嵌计费页，不自动挂到 Dashboard
    protected static bool $isDiscovered = false;

    // 看板查询轻量（2 次 Redis GET + 3 次 COUNT），随页面首屏直接渲染（不懒加载）
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollInterval = '30s';

    /** 资源数统计口径：limitKey => [标签, 表名, 是否仅 active=1] */
    private const RESOURCE_COUNTERS = [
        'max_exchange_accounts' => ['交易所账户', 'exchange_accounts', false],
        'max_strategy_instances' => ['策略实例', 'strategy_instances', false],
        'max_signal_subscriptions' => ['信号订阅', 'signal_subscriptions', true],
    ];

    /**
     * @return array{daily: array<int, array{label: string, used: int, limit: int}>, resources: array<int, array{label: string, used: int, limit: int}>}
     */
    public function getUsageProperty(): array
    {
        $tenantId = (int) TenantContext::getTenantId();

        // QuotaGuard 为配额统一门面（tenant 解析经它进入，不直读模型）
        $tenant = app(QuotaGuard::class)->tenant($tenantId);
        $plans = app(PlanService::class);
        $meter = app(TwoPhaseMeter::class);

        $daily = [];
        foreach (TwoPhaseMeter::DAILY_LIMIT_KEYS as $eventType => $limitKey) {
            $daily[] = [
                'label' => $eventType === 'ai_call' ? 'AI 调用（今日）' : '下单（今日）',
                'used' => $meter->usedTodayOrDb($tenantId, $eventType),
                'limit' => $plans->limit($tenant, $limitKey),
            ];
        }

        $resources = [];
        foreach (self::RESOURCE_COUNTERS as $limitKey => [$label, $table, $activeOnly]) {
            $query = DB::table($table)->where('tenant_id', $tenantId);
            if ($activeOnly) {
                $query->where('active', 1);
            }

            $resources[] = [
                'label' => $label,
                'used' => (int) $query->count(),
                'limit' => $plans->limit($tenant, $limitKey),
            ];
        }

        return ['daily' => $daily, 'resources' => $resources];
    }
}
