<?php

declare(strict_types=1);

namespace App\Domain\Strategy;

use App\Domain\Billing\QuotaGuard;
use App\Models\Strategy;
use App\Models\StrategyInstance;
use App\Models\Tenant;
use RuntimeException;

/**
 * 策略实例创建网关（套餐门控 + 落库）。
 *
 * 创建策略实例前必须通过两道闸门：
 *  1. 租户权益（QuotaGuard::assertEntitled）
 *  2. 策略售卖白名单（QuotaGuard::assertStrategyAllowed）
 *
 * 这是 POC 阶段的轻量网关；P1 阶段会拆出 Application Service + DTO。
 */
class StrategyGateway
{
    public function __construct(
        private readonly QuotaGuard $quotaGuard,
    ) {
    }

    /**
     * 创建策略实例。
     *
     * @param  array<string,mixed>  $params
     */
    public function createInstance(
        Tenant $tenant,
        string $strategyCode,
        int $exchangeAccountId,
        string $symbol,
        string $interval,
        array $params,
        string $allocatedCapital,
        ?int $leverage = null,
    ): StrategyInstance {
        $tenantId = (int) $tenant->getKey();

        // 1. 权益校验
        $this->quotaGuard->assertEntitled($tenantId);

        // 2. 策略售卖门控（套餐白名单 ∩ config('strategy.implemented')）
        $this->quotaGuard->assertStrategyAllowed($tenantId, $strategyCode);

        // 3. 策略目录行必须存在且启用
        $strategy = Strategy::query()
            ->where('code', $strategyCode)
            ->where('enabled', true)
            ->first();

        if ($strategy === null) {
            throw new RuntimeException("Strategy {$strategyCode} not found or disabled");
        }

        // 4. 落库
        return StrategyInstance::query()->create([
            'tenant_id' => $tenantId,
            'strategy_id' => $strategy->getKey(),
            'exchange_account_id' => $exchangeAccountId,
            'symbol' => $symbol,
            'interval' => $interval,
            'params' => $params,
            'allocated_capital' => $allocatedCapital,
            'leverage' => $leverage,
            'status' => 'stopped',
            'breaker_state' => 'closed',
        ]);
    }
}
