<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Futures;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Strategy\Base\AbstractStrategy;
use App\Domain\Strategy\Contracts\RecoverablePendingOrders;
use App\Domain\Strategy\Contracts\StrategyContext;
use Illuminate\Support\Facades\Log;

/**
 * 合约网格策略（单向持仓模式）。
 *
 * 在 [lower_price, upper_price] 区间均分 grid_count 格，
 * 每格名义价值 = (margin / grid_count) × leverage，数量 = 名义价值 / price。
 *
 * 决策逻辑（research.md §3.2）：
 *   价格下行（index 减小）：
 *     - 无持仓   → 开多（BUY，reduceOnly=false）
 *     - 持空仓   → 平空（BUY，reduceOnly=true）
 *     - 持多仓   → 加多（BUY，reduceOnly=false）
 *   价格上行（index 增大）：
 *     - 无持仓   → 开空（SELL，reduceOnly=false）
 *     - 持多仓   → 平多（SELL，reduceOnly=true）
 *     - 持空仓   → 加空（SELL，reduceOnly=false）
 *
 * 状态结构（positionSnapshot，schema_version=2）：
 *   - grid_step
 *   - last_index
 *   - position: {side, size, entry_price, leverage, liquidation_price, maint_margin_rate}
 *   - pending: {cid, side, qty, reduce_only}  崩溃恢复核单用
 *
 * 与现货网格差异：
 *   - 双向开仓（多/空），平仓用 reduceOnly 反向单
 *   - 单一 position 快照（非 grids 数组）
 *   - 强平价需由仓位风控监控
 */
class FuturesGridStrategy extends AbstractStrategy implements RecoverablePendingOrders
{
    protected const SCHEMA_VERSION = 2;

    private string $gridStep = '0';

    private int $lastIndex = -2;

    private bool $hydrated = false;

    /** @var array{side:string,size:string,entry_price:string,leverage:int,liquidation_price:?string,maint_margin_rate:string}|null */
    private ?array $position = null;

    /** @var array{side:string,qty:string,price:?string,reduce_only:bool,leverage:int,signal_type:string}|null */
    private ?array $pendingSignal = null;

    /** @var array<string,array{side:string,qty:string,reduce_only:bool}> clientOrderId → pending 成交关联 */
    private array $pendingFills = [];

    /** @var array{cid:string,side:string,qty:string,reduce_only:bool}|null 崩溃恢复 pending 单 */
    private ?array $pendingOrder = null;

    /** @var array{reduce_only:bool,leverage:int}|null 最近消费信号的元数据（attachClientOrderId 用） */
    private ?array $lastSignalMeta = null;

    public function onTick(ExchangeTicker $ticker): void
    {
        // 网格策略按 bar close 决策
    }

    public function onBar(ExchangeKline $bar): void
    {
        $this->lastBarTime = $bar->closeTime;

        $this->hydrateFromSnapshot();

        if (! $this->isConfigured()) {
            return;
        }

        $price = $bar->close;
        if (bccomp($price, '0', 12) !== 1) {
            return;
        }

        $index = $this->indexOf($price);

        // 首 bar 只记录位置，不交易
        if ($this->lastIndex === -2) {
            $this->lastIndex = $index;
            $this->flushSnapshot();

            return;
        }

        // 已有 pending 单未成交：不产生新信号
        if ($this->pendingOrder !== null) {
            $this->lastIndex = $index;
            $this->flushSnapshot();

            return;
        }

        $signal = null;

        // 区间外（跌破下沿 index=-1 或涨破上沿 index=grid_count）不动作
        $count = (int) ($this->params['grid_count'] ?? 10);
        $inRange = $index >= 0 && $index < $count;

        if ($inRange) {
            if ($index < $this->lastIndex) {
                // 价格下行
                $signal = $this->downwardSignal($price);
            } elseif ($index > $this->lastIndex) {
                // 价格上行
                $signal = $this->upwardSignal($price);
            }
        }

        $this->lastIndex = $index;
        $this->pendingSignal = $signal;
        $this->flushSnapshot();
    }

    public function onFill(ExchangeOrder $order): void
    {
        $this->hydrateFromSnapshot();

        $ref = $this->pendingFills[$order->clientOrderId] ?? null;
        if ($ref === null) {
            return;
        }
        unset($this->pendingFills[$order->clientOrderId]);

        $filledQty = $order->filledQty !== '0' ? $order->filledQty : $order->quantity;

        if ($order->status !== 'filled') {
            // 失败/撤销：清理 pending 标记，允许后续重新触发
            $this->pendingOrder = null;
            $this->flushSnapshot();

            return;
        }

        if ($ref['reduce_only']) {
            // 平仓：减少对应方向持仓
            $this->applyCloseFill($order->side, $filledQty);
        } else {
            // 开仓/加仓：增加对应方向持仓
            $this->applyOpenFill($order->side, $filledQty, $order->avgFillPrice ?? '0');
        }

        $this->pendingOrder = null;
        $this->flushSnapshot();
    }

    public function shouldOpen(StrategyContext $ctx): ?array
    {
        if ($this->pendingSignal === null || $this->pendingSignal['reduce_only'] === true) {
            return null;
        }

        return $this->consumeSignal();
    }

    public function shouldClose(StrategyContext $ctx): ?array
    {
        if ($this->pendingSignal === null || $this->pendingSignal['reduce_only'] === false) {
            return null;
        }

        return $this->consumeSignal();
    }

    /**
     * Runner 下单前注入 clientOrderId → pending 关联。
     * 必须在 shouldOpen/shouldClose 消费信号之后、placeOrder 之前调用。
     */
    public function attachClientOrderId(string $clientOrderId, string $side, string $qty): void
    {
        $meta = $this->lastSignalMeta;
        if ($meta === null) {
            return;
        }
        $this->lastSignalMeta = null;

        $reduceOnly = (bool) $meta['reduce_only'];

        $this->pendingFills[$clientOrderId] = [
            'side' => $side,
            'qty' => $qty,
            'reduce_only' => $reduceOnly,
        ];

        $this->pendingOrder = [
            'cid' => $clientOrderId,
            'side' => $side,
            'qty' => $qty,
            'reduce_only' => $reduceOnly,
        ];

        $this->flushSnapshot();
    }

    // ------------------------------------------------------------------
    // RecoverablePendingOrders
    // ------------------------------------------------------------------

    public function pendingClientOrderIds(): array
    {
        $this->hydrateFromSnapshot();

        if ($this->pendingOrder !== null && ! empty($this->pendingOrder['cid'])) {
            return [(string) $this->pendingOrder['cid']];
        }

        return [];
    }

    public function applyRecoveredOrder(ExchangeOrder $order): void
    {
        $this->hydrateFromSnapshot();

        if ($this->pendingOrder === null || $this->pendingOrder['cid'] !== $order->clientOrderId) {
            return;
        }

        $reduceOnly = (bool) $this->pendingOrder['reduce_only'];
        $filledQty = $order->filledQty !== '0' ? $order->filledQty : $order->quantity;

        if ($order->status === 'filled') {
            if ($reduceOnly) {
                $this->applyCloseFill($order->side, $filledQty);
            } else {
                $this->applyOpenFill($order->side, $filledQty, $order->avgFillPrice ?? '0');
            }
        }
        // canceled/rejected/failed/查无：pendingOrder 置空，后续 bar 可重新触发

        $this->pendingOrder = null;
        $this->flushSnapshot();
    }

    /**
     * 当前持仓数量（带方向符号：多正空负）。
     */
    public function currentPositionQty(): string
    {
        if ($this->position === null) {
            return '0';
        }

        $size = $this->position['size'];

        return $this->position['side'] === 'long' ? $size : '-' . $size;
    }

    /**
     * 崩溃恢复后与交易所真实持仓核对，不一致记 warning（不自动改账）。
     */
    public function reconcileWithExchangePosition(?ExchangePosition $exchangePosition): void
    {
        if ($this->position === null) {
            if ($exchangePosition !== null && bccomp($exchangePosition->size, '0', 8) === 1) {
                Log::warning('FuturesGrid position drift: local empty but exchange has position', [
                    'instance' => $this->instanceId,
                    'exchange_side' => $exchangePosition->side,
                    'exchange_size' => $exchangePosition->size,
                ]);
            }

            return;
        }

        if ($exchangePosition === null || bccomp($exchangePosition->size, '0', 8) !== 1) {
            Log::warning('FuturesGrid position drift: local has position but exchange empty', [
                'instance' => $this->instanceId,
                'local_side' => $this->position['side'],
                'local_size' => $this->position['size'],
            ]);

            return;
        }

        if ($this->position['side'] !== $exchangePosition->side
            || bccomp($this->position['size'], $exchangePosition->size, 8) !== 0) {
            Log::warning('FuturesGrid position drift: local vs exchange mismatch', [
                'instance' => $this->instanceId,
                'local' => $this->position,
                'exchange' => [
                    'side' => $exchangePosition->side,
                    'size' => $exchangePosition->size,
                    'entry_price' => $exchangePosition->entryPrice,
                ],
            ]);
        }
    }

    // ------------------------------------------------------------------
    // 决策逻辑
    // ------------------------------------------------------------------

    /**
     * 价格下行信号。
     */
    private function downwardSignal(string $price): ?array
    {
        $leverage = (int) ($this->params['leverage'] ?? 5);

        if ($this->position === null) {
            // 无持仓 → 开多
            return $this->makeSignal('buy', $price, false, $leverage, 'open_long');
        }

        if ($this->position['side'] === 'short') {
            // 持空 → 平空（reduceOnly）
            return $this->makeSignal('buy', $price, true, $leverage, 'close_short');
        }

        // 持多 → 加多
        return $this->makeSignal('buy', $price, false, $leverage, 'add_long');
    }

    /**
     * 价格上行信号。
     */
    private function upwardSignal(string $price): ?array
    {
        $leverage = (int) ($this->params['leverage'] ?? 5);

        if ($this->position === null) {
            // 无持仓 → 开空
            return $this->makeSignal('sell', $price, false, $leverage, 'open_short');
        }

        if ($this->position['side'] === 'long') {
            // 持多 → 平多（reduceOnly）
            return $this->makeSignal('sell', $price, true, $leverage, 'close_long');
        }

        // 持空 → 加空
        return $this->makeSignal('sell', $price, false, $leverage, 'add_short');
    }

    /**
     * 构造信号数组。
     *
     * @return array{side:string,qty:string,price:null,reduce_only:bool,leverage:int,signal_type:string}
     */
    private function makeSignal(string $side, string $price, bool $reduceOnly, int $leverage, string $signalType): array
    {
        // 平仓数量 = 当前持仓 size；开仓/加仓数量 = 每格数量
        if ($reduceOnly && $this->position !== null) {
            $qty = $this->position['size'];
        } else {
            $qty = $this->qtyPerGrid($price);
        }

        if ($qty === null) {
            return [];
        }

        return [
            'side' => $side,
            'qty' => $qty,
            'price' => null,
            'reduce_only' => $reduceOnly,
            'leverage' => $leverage,
            'signal_type' => $signalType,
        ];
    }

    // ------------------------------------------------------------------
    // onFill 入账
    // ------------------------------------------------------------------

    /**
     * 开仓/加仓入账。
     */
    private function applyOpenFill(string $side, string $qty, string $fillPrice): void
    {
        $positionSide = $side === 'buy' ? 'long' : 'short';

        if ($this->position === null) {
            // 新开仓
            $this->position = [
                'side' => $positionSide,
                'size' => $this->trimZeros($qty),
                'entry_price' => $fillPrice,
                'leverage' => (int) ($this->params['leverage'] ?? 5),
                'liquidation_price' => null,
                'maint_margin_rate' => '0.0050',
            ];

            return;
        }

        if ($this->position['side'] === $positionSide) {
            // 同向加仓：加权平均成本
            $oldSize = $this->position['size'];
            $oldCost = $this->position['entry_price'];
            $newSize = bcadd($oldSize, $qty, 8);
            $newCost = bcdiv(
                bcadd(bcmul($oldCost, $oldSize, 12), bcmul($fillPrice, $qty, 12), 12),
                $newSize,
                12,
            );

            $this->position['size'] = $this->trimZeros($newSize);
            $this->position['entry_price'] = $newCost;
        }
        // 异向开仓（理论上不会发生，平仓走 reduceOnly 分支）
    }

    /**
     * 平仓入账（reduceOnly）。
     */
    private function applyCloseFill(string $side, string $qty): void
    {
        if ($this->position === null) {
            return;
        }

        $remaining = bcsub($this->position['size'], $qty, 8);

        if (bccomp($remaining, '0', 8) <= 0) {
            // 全平
            $this->position = null;
        } else {
            // 部分减仓
            $this->position['size'] = $this->trimZeros($remaining);
        }
    }

    // ------------------------------------------------------------------
    // 工具
    // ------------------------------------------------------------------

    private function consumeSignal(): ?array
    {
        $signal = $this->pendingSignal;
        if ($signal === null) {
            return null;
        }

        $this->pendingSignal = null;

        // 保存元数据供 attachClientOrderId 使用（信号已被消费，pendingSignal 置 null）
        $this->lastSignalMeta = [
            'reduce_only' => $signal['reduce_only'],
            'leverage' => $signal['leverage'],
        ];

        return [
            'side' => $signal['side'],
            'qty' => $signal['qty'],
            'price' => $signal['price'],
            'reduce_only' => $signal['reduce_only'],
            'leverage' => $signal['leverage'],
        ];
    }

    private function isConfigured(): bool
    {
        $upper = $this->params['upper_price'] ?? null;
        $lower = $this->params['lower_price'] ?? null;
        $count = (int) ($this->params['grid_count'] ?? 10);

        if (! is_numeric($upper) || ! is_numeric($lower) || $count < 1) {
            return false;
        }

        if ($this->gridStep === '0') {
            $step = bcdiv(bcsub((string) $upper, (string) $lower, 12), (string) $count, 12);
            if (bccomp($step, '0', 12) !== 1) {
                return false;
            }
            $this->gridStep = $step;
        }

        return true;
    }

    private function indexOf(string $price): int
    {
        $lower = (string) ($this->params['lower_price'] ?? '0');
        $count = (int) ($this->params['grid_count'] ?? 10);

        $raw = bcdiv(bcsub($price, $lower, 12), $this->gridStep, 0);
        $index = (int) $raw;

        return max(-1, min($count, $index));
    }

    /**
     * 每格数量 = (margin / grid_count) / price × leverage。
     */
    private function qtyPerGrid(string $price): ?string
    {
        $margin = (string) ($this->params['margin'] ?? '1000');
        $count = (int) ($this->params['grid_count'] ?? 10);
        $leverage = (int) ($this->params['leverage'] ?? 5);

        $marginPerGrid = bcdiv($margin, (string) $count, 12);
        $notional = bcmul($marginPerGrid, (string) $leverage, 12);
        $qty = bcdiv($notional, $price, 8);

        if (bccomp($qty, '0', 8) !== 1) {
            return null;
        }

        return $qty;
    }

    private function symbol(): string
    {
        return (string) ($this->params['symbol'] ?? 'BTCUSDT');
    }

    private function trimZeros(string $value): string
    {
        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    // ------------------------------------------------------------------
    // 状态序列化
    // ------------------------------------------------------------------

    private function flushSnapshot(): void
    {
        $this->positionSnapshot = [
            'grid_step' => $this->gridStep,
            'last_index' => $this->lastIndex,
            'position' => $this->position,
            'pending_order' => $this->pendingOrder,
        ];
    }

    private function hydrateFromSnapshot(): void
    {
        if ($this->hydrated || $this->positionSnapshot === null) {
            return;
        }
        $this->hydrated = true;

        $snap = $this->positionSnapshot;
        $step = (string) ($snap['grid_step'] ?? '0');
        if ($step !== '0') {
            $this->gridStep = $step;
        }
        $this->lastIndex = (int) ($snap['last_index'] ?? -2);

        if (isset($snap['position']) && is_array($snap['position'])) {
            $p = $snap['position'];
            $this->position = [
                'side' => (string) ($p['side'] ?? 'long'),
                'size' => (string) ($p['size'] ?? '0'),
                'entry_price' => (string) ($p['entry_price'] ?? '0'),
                'leverage' => (int) ($p['leverage'] ?? ($this->params['leverage'] ?? 5)),
                'liquidation_price' => isset($p['liquidation_price']) ? (string) $p['liquidation_price'] : null,
                'maint_margin_rate' => (string) ($p['maint_margin_rate'] ?? '0.0050'),
            ];
            if (bccomp($this->position['size'], '0', 8) !== 1) {
                $this->position = null;
            }
        }

        if (isset($snap['pending_order']) && is_array($snap['pending_order'])) {
            $po = $snap['pending_order'];
            $this->pendingOrder = [
                'cid' => (string) ($po['cid'] ?? ''),
                'side' => (string) ($po['side'] ?? 'buy'),
                'qty' => (string) ($po['qty'] ?? '0'),
                'reduce_only' => (bool) ($po['reduce_only'] ?? false),
            ];
        }
    }
}
