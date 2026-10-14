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
 * 现货网格策略（分层网格，P1 核心闭环实现）。
 *
 * 在 [lower_price, upper_price] 区间均分 grid_count 个格子，
 * 每格投入 investment_amount / grid_count（USDT 计价）：
 *  - 价格下穿进入某格 → 买入该格（pending 标记 → 下单 → onFill 提交）
 *  - 价格上穿离开某格 → 卖出该格持仓（该格止盈）
 *  - 区间外（跌破下沿/涨破上沿）不动作
 *
 * 状态结构（positionSnapshot，持久化到 Redis）：
 *  - grid_step      每格价格步长
 *  - last_index     当前价格所在格索引（-1 = 区间下方，grid_count = 区间上方）
 *  - grids          {格索引: {qty, cost, pending, cid?}}  qty=持有 base 数量，cost=买入均价，cid=pending 买单 clientOrderId
 *  - position_qty   总持有数量
 *  - avg_cost       加权平均成本
 *
 * 崩溃恢复语义（P5.5 步骤 1 增强）：restore 后由 PendingOrderRecovery 对 pending
 * 格携带的 cid 调 getOrderByClientOrderId 核实——filled 入账、canceled/查无释放、
 * 查询异常保守保持阻塞；真实余额以 ReconciliationService 周期对账为准。
 *
 * 全部数量计算使用 bcmath（字符串），避免浮点误差。
 */
class SpotGridStrategy extends AbstractStrategy implements RecoverablePendingOrders
{
    private string $gridStep = '0';

    private int $lastIndex = -2;        // -2 = 未初始化（首 bar 不交易）

    private bool $hydrated = false;     // 崩溃快照是否已 hydrate 进内存（只能一次）

    /** @var array<int,array{qty:string,cost:string,pending:bool,cid?:string}> */
    private array $grids = [];

    /** @var array{side:string,qty:string,price:?string}|null onBar 产生的待执行信号 */
    private ?array $pendingSignal = null;

    /** @var array<string,array{cell:int,side:string,qty:string,cost:string}> clientOrderId → 挂起成交关联 */
    private array $pendingFills = [];

    public function onTick(ExchangeTicker $ticker): void
    {
        // 网格策略只按 bar close 决策，tick 仅预留
    }

    public function onBar(ExchangeKline $bar): void
    {
        $this->lastBarTime = $bar->closeTime;

        // hydrate 必须在 isConfigured() 之前：isConfigured() 首次会把 gridStep
        // 从 '0' 算成实际值，旧守卫借 gridStep!=='0' 判断会导致恢复快照永不加载。
        $this->hydrateFromSnapshot();

        if (! $this->isConfigured()) {
            return;
        }

        $price = $bar->close;
        if (bccomp($price, '0', 12) !== 1) {
            return;
        }

        $index = $this->indexOf($price);
        $pendingSignal = null;

        if ($this->lastIndex !== -2) {
            if ($index > $this->lastIndex) {
                // 价格上行：途经的格子逐格止盈（卖出有持仓的格）
                for ($cell = $this->lastIndex; $cell < $index; $cell++) {
                    $held = $this->grids[$cell] ?? null;
                    if ($held !== null && bccomp($held['qty'], '0', 8) === 1 && ! $held['pending']) {
                        $pendingSignal = ['side' => 'sell', 'qty' => $held['qty'], 'price' => null, 'cell' => $cell];
                        break; // 单 bar 只产生一个信号
                    }
                }
            } elseif ($index < $this->lastIndex && $index >= 0) {
                // 价格下行进入新格：该格空闲则买入
                $cell = $index;
                if (! isset($this->grids[$cell])) {
                    $qty = $this->baseQtyPerGrid($price);
                    if ($qty !== null) {
                        // 先标记 pending（状态随后被 Runner 持久化），再由 Runner 下单
                        $this->grids[$cell] = ['qty' => '0', 'cost' => '0', 'pending' => true];
                        $pendingSignal = ['side' => 'buy', 'qty' => $qty, 'price' => null, 'cell' => $cell];
                    }
                }
            }
        }

        $this->lastIndex = $index;
        $this->pendingSignal = $pendingSignal;
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

        $cell = $ref['cell'];

        if ($order->side === 'buy' && $order->status === 'filled') {
            $qty = $order->filledQty !== '0' ? $order->filledQty : $ref['qty'];
            $cost = $order->avgFillPrice ?? $ref['cost'];
            $this->grids[$cell] = ['qty' => $qty, 'cost' => $cost, 'pending' => false];
        } elseif ($order->side === 'sell' && $order->status === 'filled') {
            $sellQty = $order->filledQty !== '0' ? $order->filledQty : $ref['qty'];
            $sellPrice = $order->avgFillPrice ?? '0';
            $pnl = bcsub(bcmul($sellPrice, $sellQty, 12), bcmul($ref['cost'], $sellQty, 12), 12);
            $this->realizedPnl = bcadd($this->realizedPnl, $pnl, 12);

            if (bccomp($pnl, '0', 12) === -1) {
                $this->consecutiveLosses++;
            } else {
                $this->consecutiveLosses = 0;
            }

            unset($this->grids[$cell]);
        } else {
            // 下单失败/被拒/撤销：释放 pending 格，允许后续重新触发
            if (($this->grids[$cell]['pending'] ?? false) === true) {
                unset($this->grids[$cell]);
            }
        }

        $this->flushSnapshot();
    }

    public function shouldOpen(StrategyContext $ctx): ?array
    {
        if ($this->pendingSignal === null || $this->pendingSignal['side'] !== 'buy') {
            return null;
        }

        $signal = $this->pendingSignal;
        $this->pendingSignal = null;
        $this->trackPendingFill($signal);

        return ['side' => $signal['side'], 'qty' => $signal['qty'], 'price' => $signal['price']];
    }

    public function shouldClose(StrategyContext $ctx): ?array
    {
        if ($this->pendingSignal === null || $this->pendingSignal['side'] !== 'sell') {
            return null;
        }

        $signal = $this->pendingSignal;
        $this->pendingSignal = null;
        $this->trackPendingFill($signal);

        return ['side' => $signal['side'], 'qty' => $signal['qty'], 'price' => $signal['price']];
    }

    /**
     * Runner 下单前注入 clientOrderId → 格位关联（onFill 关联用）。
     * 必须在 shouldOpen/shouldClose 消费信号之后、placeOrder 之前调用。
     */
    public function attachClientOrderId(string $clientOrderId, string $side, string $qty): void
    {
        $last = $this->lastSignalMeta;
        if ($last === null) {
            return;
        }
        $this->lastSignalMeta = null;

        $this->pendingFills[$clientOrderId] = [
            'cell' => $last['cell'],
            'side' => $side,
            'qty' => $qty,
            'cost' => $last['cost'],
        ];

        // pending 格快照携带 cid：崩溃后 PendingOrderRecovery 据此向交易所核实
        $cell = $last['cell'];
        if (($this->grids[$cell]['pending'] ?? false) === true) {
            $this->grids[$cell]['cid'] = $clientOrderId;
            $this->flushSnapshot();
        }
    }

    // ------------------------------------------------------------------
    // RecoverablePendingOrders（崩溃恢复 pending 单核实）
    // ------------------------------------------------------------------

    public function pendingClientOrderIds(): array
    {
        $this->hydrateFromSnapshot();

        $cids = [];
        foreach ($this->grids as $g) {
            if (($g['pending'] ?? false) === true && ! empty($g['cid'])) {
                $cids[] = (string) $g['cid'];
            }
        }

        return $cids;
    }

    public function applyRecoveredOrder(ExchangeOrder $order): void
    {
        $this->hydrateFromSnapshot();

        $cell = null;
        foreach ($this->grids as $index => $g) {
            if (($g['pending'] ?? false) === true && ($g['cid'] ?? '') === $order->clientOrderId) {
                $cell = $index;
                break;
            }
        }
        if ($cell === null) {
            return; // 无对应 pending 格（可能已被正常 onFill 处理），幂等忽略
        }

        if ($order->side === 'buy' && $order->status === 'filled') {
            $qty = bccomp($order->filledQty, '0', 8) === 1 ? $order->filledQty : $order->quantity;
            $cost = $order->avgFillPrice ?? '0';
            $this->grids[$cell] = ['qty' => $qty, 'cost' => $cost, 'pending' => false];
        } else {
            // canceled/rejected/failed/查无此单：释放格位，后续 bar 可重新触发
            unset($this->grids[$cell]);
        }

        $this->flushSnapshot();
    }

    /**
     * 当前持仓快照（Runner 刷新 DB position_snapshot 用）。
     */
    public function currentPositionQty(): string
    {
        $total = '0';
        foreach ($this->grids as $g) {
            $total = bcadd($total, $g['qty'], 8);
        }

        return $total;
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    private ?array $lastSignalMeta = null;

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

    /**
     * 价格所在格索引：floor((price - lower) / step)，区间下方 -1，上方 grid_count。
     */
    private function indexOf(string $price): int
    {
        $lower = (string) ($this->params['lower_price'] ?? '0');
        $count = (int) ($this->params['grid_count'] ?? 10);

        $raw = bcdiv(bcsub($price, $lower, 12), $this->gridStep, 0);
        $index = (int) $raw;

        return max(-1, min($count, $index));
    }

    /**
     * 每格 base 数量 = (investment_amount / grid_count) / price，8 位小数截断。
     */
    private function baseQtyPerGrid(string $price): ?string
    {
        $investment = (string) ($this->params['investment_amount'] ?? '1000');
        $count = (int) ($this->params['grid_count'] ?? 10);

        $quotePerGrid = bcdiv($investment, (string) $count, 12);
        $qty = bcdiv($quotePerGrid, $price, 8);

        if (bccomp($qty, '0', 8) !== 1) {
            return null;
        }

        return $qty;
    }

    private function trackPendingFill(array $signal): void
    {
        // 记录最近一次信号的格位与成本引用，attachClientOrderId 时绑定
        $this->lastSignalMeta = [
            'cell' => $signal['cell'],
            'cost' => $signal['side'] === 'buy' ? '0' : (string) ($this->grids[$signal['cell']]['cost'] ?? '0'),
        ];
    }

    /**
     * 内存态 → positionSnapshot（由 Runner 持久化到 Redis/DB）。
     */
    private function flushSnapshot(): void
    {
        $grids = [];
        foreach ($this->grids as $cell => $g) {
            $grids[(string) $cell] = $g;
        }

        $this->positionSnapshot = [
            'grid_step' => $this->gridStep,
            'last_index' => $this->lastIndex,
            'grids' => $grids,
        ];
    }

    /**
     * positionSnapshot → 内存态（仅崩溃恢复时执行一次）。
     *
     * 用独立 hydrated 标志判定，不能借用 gridStep==='0'：onBar 中
     * isConfigured() 会先把 gridStep 算成实际值，导致这里误判"已初始化"
     * 而跳过恢复，重启后持仓/pending/lastIndex 全丢。
     */
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
        $this->grids = [];
        foreach ((array) ($snap['grids'] ?? []) as $cell => $g) {
            $entry = [
                'qty' => (string) ($g['qty'] ?? '0'),
                'cost' => (string) ($g['cost'] ?? '0'),
                'pending' => (bool) ($g['pending'] ?? false),
            ];
            if (! empty($g['cid'])) {
                $entry['cid'] = (string) $g['cid'];
            }
            $this->grids[(int) $cell] = $entry;
        }
    }
}
