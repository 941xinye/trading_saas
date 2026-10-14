<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Spot;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Strategy\Base\AbstractStrategy;
use App\Domain\Strategy\Contracts\RecoverablePendingOrders;
use App\Domain\Strategy\Contracts\StrategyContext;

/**
 * 现货 DCA（定投）策略（P5.5 步骤 2 落地，销债 B1）。
 *
 * 语义（时间驱动，与价格无关）：
 *  - 收到的第一根 bar 只锚定 anchor_at（该 bar 不下单）
 *  - 自 anchor（未成交过）或上一笔成交时间起，每 interval_minutes 产生一笔
 *    定额市价买单：qty = amount_per_order / close（bcmath，8 位截断）
 *  - 累计成交 max_orders 笔后永久停止
 *  - 下单失败/撤销：不计次数、冷却不推进（下一周期可重试）
 *  - 只买不卖（shouldClose 永远 null）
 *
 * 状态结构（positionSnapshot）：
 *  - anchor_at       首根 bar closeTime（UTC 秒）
 *  - orders_count    已成交笔数
 *  - total_spent     累计实际成交花费（avgFillPrice*filledQty，USDT）
 *  - last_order_at   最近一笔成交时间（UTC 秒）
 *  - pending_cid     在途买单 clientOrderId（单飞；崩溃恢复核实用）
 *
 * 崩溃恢复：pending_cid 存在时由 PendingOrderRecovery 向交易所核实，
 * filled → 入账推进冷却；canceled/查无 → 清除 cid（冷却不推进）；
 * 查询异常 → 保留 cid 且期间不产生新信号（绝不重复下单）。
 */
class SpotDcaStrategy extends AbstractStrategy implements RecoverablePendingOrders
{
    private bool $hydrated = false;

    private ?int $anchorAt = null;

    private int $ordersCount = 0;

    private string $totalSpent = '0';

    private ?int $lastOrderAt = null;

    private ?string $pendingCid = null;

    /** @var array{side:string,qty:string,price:?string}|null */
    private ?array $pendingSignal = null;

    public function onTick(ExchangeTicker $ticker): void
    {
        // DCA 按 bar close 决策，tick 不动作
    }

    public function onBar(ExchangeKline $bar): void
    {
        $this->lastBarTime = $bar->closeTime;
        $this->hydrateFromSnapshot();
        $this->pendingSignal = null;

        if (! $this->isConfigured()) {
            return;
        }

        $closeSec = intdiv($bar->closeTime, 1000);
        if ($this->anchorAt === null) {
            // 首根 bar 只锚定，不产生信号
            $this->anchorAt = $closeSec;
            $this->flushSnapshot();

            return;
        }

        // 在途单未决（崩溃后查单网络异常等）：单飞，绝不重复下单
        if ($this->pendingCid !== null || $this->ordersCount >= $this->maxOrders()) {
            return;
        }

        $reference = $this->ordersCount === 0 ? $this->anchorAt : $this->lastOrderAt;
        if ($reference === null) {
            return;
        }

        if ($closeSec - $reference < $this->intervalSeconds()) {
            return;
        }

        $price = $bar->close;
        if (bccomp($price, '0', 12) !== 1) {
            return;
        }

        $qty = bcdiv((string) $this->amount(), $price, 8);
        if (bccomp($qty, '0', 8) !== 1) {
            // 定额过小导致 8 位截断为 0：不产生信号（防止废单）
            return;
        }

        $this->pendingSignal = ['side' => 'buy', 'qty' => $qty, 'price' => null];
        $this->flushSnapshot();
    }

    public function onFill(ExchangeOrder $order): void
    {
        $this->hydrateFromSnapshot();

        if ($this->pendingCid === null || $order->clientOrderId !== $this->pendingCid) {
            return;
        }

        $this->resolvePendingOrder($order);
    }

    public function shouldOpen(StrategyContext $ctx): ?array
    {
        if ($this->pendingSignal === null || $this->pendingSignal['side'] !== 'buy') {
            return null;
        }

        $signal = $this->pendingSignal;
        $this->pendingSignal = null;

        return ['side' => $signal['side'], 'qty' => $signal['qty'], 'price' => $signal['price']];
    }

    public function shouldClose(StrategyContext $ctx): ?array
    {
        return null;
    }

    /**
     * Runner 下单前注入 clientOrderId（shouldOpen 消费信号之后、placeOrder 之前调用）。
     * DCA 单飞：已有在途 cid 时拒绝重复绑定。
     */
    public function attachClientOrderId(string $clientOrderId, string $side, string $qty): void
    {
        if ($this->pendingCid !== null) {
            return;
        }

        $this->pendingCid = $clientOrderId;
        $this->flushSnapshot();
    }

    // ------------------------------------------------------------------
    // RecoverablePendingOrders
    // ------------------------------------------------------------------

    public function pendingClientOrderIds(): array
    {
        $this->hydrateFromSnapshot();

        return $this->pendingCid === null ? [] : [$this->pendingCid];
    }

    public function applyRecoveredOrder(ExchangeOrder $order): void
    {
        $this->hydrateFromSnapshot();

        if ($this->pendingCid === null || $order->clientOrderId !== $this->pendingCid) {
            return;
        }

        $this->resolvePendingOrder($order);
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    private function resolvePendingOrder(ExchangeOrder $order): void
    {
        if ($order->side === 'buy' && $order->status === 'filled') {
            $qty = bccomp($order->filledQty, '0', 8) === 1 ? $order->filledQty : $order->quantity;
            $price = $order->avgFillPrice ?? '0';

            $this->ordersCount++;
            $this->totalSpent = bcadd($this->totalSpent, bcmul($price, $qty, 12), 12);
            $this->lastOrderAt = $order->filledAt ?? ($this->lastBarTime !== null ? intdiv($this->lastBarTime, 1000) : time());
        }
        // canceled/rejected/failed/查无此单：不计次数、不推进冷却，下一周期可重试

        $this->pendingCid = null;
        $this->flushSnapshot();
    }

    private function isConfigured(): bool
    {
        $amount = (string) ($this->params['amount_per_order'] ?? '0');
        $interval = (int) ($this->params['interval_minutes'] ?? 0);
        $max = $this->maxOrders();

        return is_numeric($amount)
            && bccomp($amount, '0', 12) === 1
            && $interval > 0
            && $max > 0;
    }

    private function amount(): string
    {
        return (string) $this->params['amount_per_order'];
    }

    private function intervalSeconds(): int
    {
        return max(1, (int) $this->params['interval_minutes']) * 60;
    }

    private function maxOrders(): int
    {
        return max(1, (int) ($this->params['max_orders'] ?? 1));
    }

    private function flushSnapshot(): void
    {
        $this->positionSnapshot = [
            'anchor_at' => $this->anchorAt,
            'orders_count' => $this->ordersCount,
            'total_spent' => $this->totalSpent,
            'last_order_at' => $this->lastOrderAt,
            'pending_cid' => $this->pendingCid,
        ];
    }

    private function hydrateFromSnapshot(): void
    {
        if ($this->hydrated || $this->positionSnapshot === null) {
            return;
        }
        $this->hydrated = true;

        $snap = $this->positionSnapshot;
        $this->anchorAt = isset($snap['anchor_at']) ? (int) $snap['anchor_at'] : null;
        $this->ordersCount = (int) ($snap['orders_count'] ?? 0);
        $this->totalSpent = (string) ($snap['total_spent'] ?? '0');
        $this->lastOrderAt = isset($snap['last_order_at']) ? (int) $snap['last_order_at'] : null;
        $this->pendingCid = isset($snap['pending_cid']) && $snap['pending_cid'] !== null
            ? (string) $snap['pending_cid']
            : null;
    }
}
