<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Recovery;

use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Strategy\Contracts\RecoverablePendingOrders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 崩溃恢复 pending 单核实器（P5.5 步骤 1，销债 A3 的挂单部分）。
 *
 * 无 Swoole 依赖的普通类：StrategyRunner 启动协程时在订阅行情前调用一次，
 * 也可被 PHPUnit 直接测试。
 *
 * 策略语义（见 RecoverablePendingOrders）：
 *  filled → 入账；canceled/rejected/查无 → 释放；submitted/partial → 继续挂；
 *  查询异常 → 保守保持 pending（可能已成交，绝不允许重复下单）。
 */
class PendingOrderRecovery
{
    /**
     * 核实并回灌所有 pending 单。
     *
     * @return array{filled:int,released:int,submitted:int,unknown:int}
     */
    public function recover(
        RecoverablePendingOrders $strategy,
        ExchangeAdapter $adapter,
        string $symbol,
        int $tenantId,
        int $instanceId,
    ): array {
        $stats = ['filled' => 0, 'released' => 0, 'submitted' => 0, 'unknown' => 0];

        foreach ($strategy->pendingClientOrderIds() as $cid) {
            try {
                $order = $adapter->getOrderByClientOrderId($symbol, $cid);
            } catch (Throwable $e) {
                // 网络异常：保守保持阻塞，等下轮重启/人工处理
                $stats['unknown']++;
                Log::warning('Recovery getOrder failed, pending order kept blocked', [
                    'instance' => $instanceId, 'client_order_id' => $cid, 'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($order === null) {
                // 查无此单：交易所未受理（崩溃窗口在落库后、请求到达前）→ 释放占位
                $order = $this->syntheticNotFoundOrder($symbol, $cid, $tenantId, $instanceId);
                $this->markOrderRow($tenantId, $cid, [
                    'status' => 'failed',
                    'error_message' => 'order not found on exchange during crash recovery',
                ]);
                $strategy->applyRecoveredOrder($order);
                $stats['released']++;

                continue;
            }

            if (in_array($order->status, ['submitted', 'partial'], true)) {
                // 订单仍有效：pending 保持，等待真实成交回报（现货市价单一般瞬时终结）
                $stats['submitted']++;

                continue;
            }

            $this->syncOrderRow($tenantId, $cid, $order);
            $strategy->applyRecoveredOrder($order);

            if ($order->status === 'filled') {
                $stats['filled']++;
            } else {
                $stats['released']++;
            }
        }

        return $stats;
    }

    /**
     * 交易所终态回写 strategy_orders（与 OrderExecutor 成交回写同源字段）。
     */
    private function syncOrderRow(int $tenantId, string $cid, ExchangeOrder $order): void
    {
        $values = [
            'exchange_order_id' => $order->exchangeOrderId,
            'status' => $order->status,
            'filled_qty' => $order->filledQty,
            'avg_fill_price' => $order->avgFillPrice,
            'fee' => $order->fee ?? '0',
            'updated_at' => now(),
        ];
        if ($order->filledAt !== null) {
            $values['filled_at'] = date('Y-m-d H:i:s', $order->filledAt);
        }

        try {
            DB::table('strategy_orders')
                ->where('tenant_id', $tenantId)
                ->where('client_order_id', $cid)
                ->update($values);
        } catch (Throwable $e) {
            Log::warning('Recovery order row sync failed', [
                'client_order_id' => $cid, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function markOrderRow(int $tenantId, string $cid, array $extra): void
    {
        try {
            DB::table('strategy_orders')
                ->where('tenant_id', $tenantId)
                ->where('client_order_id', $cid)
                ->update($extra + ['updated_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Recovery order row mark failed', [
                'client_order_id' => $cid, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 查无此单时合成 failed 单驱动策略释放占位（side/qty 尽量以本地订单行为准）。
     */
    private function syntheticNotFoundOrder(string $symbol, string $cid, int $tenantId, int $instanceId): ExchangeOrder
    {
        $row = DB::table('strategy_orders')
            ->where('tenant_id', $tenantId)
            ->where('client_order_id', $cid)
            ->first(['side', 'quantity']);

        return new ExchangeOrder(
            exchangeOrderId: '0',
            clientOrderId: $cid,
            symbol: $symbol,
            side: (string) ($row->side ?? 'buy'),
            type: 'market',
            quantity: (string) ($row->quantity ?? '0'),
            price: null,
            stopPrice: null,
            leverage: null,
            reduceOnly: false,
            status: 'failed',
            filledQty: '0',
            avgFillPrice: null,
            fee: null,
            placedAt: time(),
            filledAt: null,
        );
    }
}
