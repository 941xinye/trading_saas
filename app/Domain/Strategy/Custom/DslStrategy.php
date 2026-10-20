<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Strategy\Base\AbstractStrategy;
use App\Domain\Strategy\Contracts\RecoverablePendingOrders;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyState;
use App\Domain\Strategy\Custom\Dsl\DslDiagnostic;
use App\Domain\Strategy\Custom\Dsl\DslEvalContext;
use App\Domain\Strategy\Custom\Dsl\DslExecutionException;
use App\Domain\Strategy\Custom\Dsl\DslInterpreter;
use App\Domain\Strategy\Custom\Dsl\DslProgram;
use App\Domain\Strategy\Custom\Dsl\DslSignal;
use App\Domain\Strategy\Custom\Dsl\DslValidationException;
use App\Domain\Strategy\Custom\Dsl\IndicatorSnapshotBuilder;
use App\Domain\Strategy\Custom\Dsl\WhitelistInterpreter;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 自定义 DSL 策略（P7 spec 006 WS3，FR-005/006/009/010/013）。
 *
 * 装载：`params['dsl_script']` → `DslParser::parse()` → 持有不可变 `DslProgram`；
 * 非法脚本（含 `meta.symbol` 与实例 symbol 不一致）在构造期即抛
 * `DslValidationException`，绝不进入运行态（FR-005）。
 *
 * 触发器（meta.trigger）：`on_bar` 在 onBar 求值；`on_tick` 在 onTick 求值；
 * `on_fill` 在 onBar 与 onFill 求值（回报后重跑四段）。
 *
 * 信号语义：白名单解释器（WhitelistInterpreter）产出 `entry`/`exit`（+ filter 带动作）信号，
 * 单飞挂到内存态 `pending_signal`；`shouldOpen`/`shouldClose` 消费并按 bcmath 换算数量
 * （`qty_pct` 开仓 = 分配资金名义额比例，平仓 = 持仓数量比例；显式 `qty` 原样透传）。
 *
 * 状态结构（`positionSnapshot`，SCHEMA_VERSION=2）：
 *   dsl.compiled_hash       = sha1(脚本)
 *   dsl.last_signal_bar     = 最近一次挂单的 bar openTime
 *   dsl.cooldown_remaining  = 冷却剩余 bar 数
 *   dsl.pending_signal      = {section,action,kind,side,qty,qty_pct,rule_index,note,client_order_id,bar_time} | null
 *   dsl.recovered           = 崩溃恢复一次性标志（constitution IV）
 *   position.qty/direction/entry_price = 内存持仓（qty_pct 平仓与止损止盈换算依据）
 *
 * 崩溃恢复：`pending_signal.client_order_id` 由 PendingOrderRecovery 核实；
 * filled → 入账；canceled/rejected/查无 → 释放占位；查询异常 → 保持挂起（绝不重复下单）。
 */
class DslStrategy extends AbstractStrategy implements RecoverablePendingOrders
{
    /** 状态 schema 版本：v2 起携带 `dsl` 扩展键（覆盖 AbstractStrategy 的 v1）。 */
    protected const SCHEMA_VERSION = 2;

    private const KIND_OPEN = 'open';

    private const KIND_CLOSE = 'close';

    private DslProgram $program;

    private DslInterpreter $interpreter;

    private IndicatorSnapshotBuilder $snapshotBuilder;

    private bool $hydrated = false;

    private string $compiledHash = '';

    private ?int $lastSignalBar = null;

    private int $cooldownRemaining = 0;

    /**
     * 待执行/在途信号（单飞）。
     *
     * @var array{section:string,action:string,kind:string,side:string,qty:?string,qty_pct:?string,rule_index:int,note:?string,client_order_id:?string,bar_time:?int}|null
     */
    private ?array $pendingSignal = null;

    /** 最近一次被消费信号的元数据（attachClientOrderId 用，pitfall #37）。 */
    private ?array $lastSignalMeta = null;

    private bool $recovered = false;

    private int $entriesThisBar = 0;

    private int $bufferSize;

    private string $positionQty = '0';

    private ?string $direction = null;

    private ?string $entryPrice = null;

    /** @var ExchangeKline[] */
    private array $recentBars = [];

    private ?ExchangeKline $lastBar = null;

    private ?ExchangeTicker $lastTicker = null;

    /** 当前 bar openTime（风控合成平仓的 bar_time 记录用） */
    private ?int $currentBar = null;

    /**
     * @param array<string,mixed> $params 须含 `dsl_script`；`symbol`/`tenant_id`/`allocated_capital` 由 Runner 注入
     *
     * @throws DslValidationException 非法 DSL 或 meta.symbol 与实例不一致（FR-005）
     */
    public function __construct(
        int $instanceId,
        array $params,
        StrategyContext $context,
        ?DslParser $parser = null,
        ?DslInterpreter $interpreter = null,
        ?IndicatorSnapshotBuilder $snapshotBuilder = null,
    ) {
        parent::__construct($instanceId, $params, $context);

        $this->program = ($parser ?? new DslParser())->parse((string) ($params['dsl_script'] ?? ''));
        $this->interpreter = $interpreter ?? new WhitelistInterpreter();
        $this->snapshotBuilder = $snapshotBuilder ?? new IndicatorSnapshotBuilder();
        $this->compiledHash = $this->program->sourceHash;
        $this->bufferSize = $this->resolveBufferSize();

        $this->assertSymbolMatches();
    }

    public function onTick(ExchangeTicker $ticker): void
    {
        $this->lastTicker = $ticker;
        $this->hydrate();

        // onTick 仅更新内存态（禁止写库/写 Redis）
        if ($this->program->meta->trigger !== 'on_tick' || $this->hasInFlightOrder()) {
            return;
        }

        $this->evaluate(null, $ticker, 'on_tick');
    }

    public function onBar(ExchangeKline $bar): void
    {
        $this->lastBarTime = $bar->closeTime;
        $this->lastBar = $bar;
        $this->hydrate();

        $this->recentBars[] = $bar;
        if (count($this->recentBars) > $this->bufferSize) {
            $this->recentBars = array_slice($this->recentBars, -$this->bufferSize);
        }

        $this->entriesThisBar = 0;
        $this->currentBar = $bar->openTime;

        // 冷却：本 bar 是否仍处冷却期（先判定再消耗一格）
        $entryBlocked = $this->cooldownRemaining > 0;
        if ($entryBlocked) {
            $this->cooldownRemaining--;
        }

        // 单飞：在途单未决期间不产出新信号（崩溃恢复期绝不重复下单）
        if ($this->hasInFlightOrder()) {
            $this->flushSnapshot();

            return;
        }

        if ($this->program->meta->trigger !== 'on_tick') {
            $this->evaluate($bar, $this->lastTicker, 'on_bar', $entryBlocked);
        }

        $this->flushSnapshot();
    }

    public function onFill(ExchangeOrder $order): void
    {
        $this->hydrate();

        $meta = $this->pendingSignal;
        $cid = $meta !== null ? ($meta['client_order_id'] ?? null) : null;
        if (is_array($meta) && is_string($cid) && $cid !== '' && $order->clientOrderId === $cid) {
            $this->resolveOrder($order, $meta);
        }

        if ($this->program->meta->trigger === 'on_fill' && ! $this->hasInFlightOrder()) {
            // on_fill 触发器：回报入账/释放后重跑四段求值（FR-006）
            $this->evaluate($this->lastBar, $this->lastTicker, 'on_fill');
        }

        $this->flushSnapshot();
    }

    public function shouldOpen(StrategyContext $ctx): ?array
    {
        $this->hydrate();

        // 单飞：在途单未决（已绑定 client_order_id）期间绝不重复产出信号（FR-010 / SC-005）
        if ($this->hasInFlightOrder()) {
            return null;
        }

        $meta = $this->pendingSignal;
        if ($meta === null || $meta['kind'] !== self::KIND_OPEN) {
            return null;
        }

        $qty = $this->resolveQuantity($meta);
        if ($qty === null || ! $this->withinMaxPosition($qty)) {
            // 数量不可得（缺参考价/截断为 0）或超出 max_position_pct：不产出（FR-009/013）
            return null;
        }

        $this->lastSignalMeta = $meta; // attachClientOrderId 绑定 cid 用

        return ['side' => $meta['side'], 'qty' => $qty, 'price' => null];
    }

    public function shouldClose(StrategyContext $ctx): ?array
    {
        $this->hydrate();

        // 单飞：在途单未决期间不重复平仓（平仓“永远放行”指不受熔断拦截，非重复下单）
        if ($this->hasInFlightOrder()) {
            return null;
        }

        // 1. 合成风控平仓（stop_loss_pct / take_profit_pct），先于 DSL exit 段
        $forced = $this->riskExitSignal();
        if ($forced !== null) {
            return $forced;
        }

        // 2. DSL exit 段信号（平仓永远放行，FR-013 / constitution V）
        $meta = $this->pendingSignal;
        if ($meta === null || $meta['kind'] !== self::KIND_CLOSE) {
            return null;
        }

        $qty = $this->resolveQuantity($meta);
        if ($qty === null) {
            return null;
        }

        $this->lastSignalMeta = $meta;

        return ['side' => $meta['side'], 'qty' => $qty, 'price' => null];
    }

    public function getState(): StrategyState
    {
        $this->flushSnapshot();

        return new StrategyState(
            schemaVersion: static::SCHEMA_VERSION,
            params: $this->params,
            positionSnapshot: $this->positionSnapshot,
            realizedPnl: $this->realizedPnl,
            consecutiveLosses: $this->consecutiveLosses,
            lastBarTime: $this->lastBarTime,
        );
    }

    public function restoreState(StrategyState $state): void
    {
        if ($state->schemaVersion > static::SCHEMA_VERSION) {
            throw new RuntimeException(sprintf(
                'DslStrategy state schema version %d is newer than supported version %d (instance %d)',
                $state->schemaVersion,
                static::SCHEMA_VERSION,
                $this->instanceId,
            ));
        }

        $snapshot = $state->positionSnapshot;
        if ($state->schemaVersion < 2) {
            $snapshot = $this->migrateV1Snapshot($snapshot);
        }

        $hash = is_array($snapshot['dsl'] ?? null) ? ($snapshot['dsl']['compiled_hash'] ?? null) : null;
        if (is_string($hash) && $hash !== '' && $hash !== $this->compiledHash) {
            Log::warning('DSL 脚本与崩溃前快照不一致（compiled_hash mismatch）', [
                'instance' => $this->instanceId,
                'state_hash' => $hash,
                'current_hash' => $this->compiledHash,
            ]);
        }

        $this->positionSnapshot = $snapshot;
        $this->realizedPnl = $state->realizedPnl;
        $this->consecutiveLosses = $state->consecutiveLosses;
        $this->lastBarTime = $state->lastBarTime;
        $this->hydrated = false;
        $this->hydrate();

        // 新进程必须重新核实崩溃前在途单 → 一次性标志复位（constitution IV）
        $this->recovered = false;
        $this->flushSnapshot();
    }

    // ------------------------------------------------------------------
    // RecoverablePendingOrders
    // ------------------------------------------------------------------

    public function pendingClientOrderIds(): array
    {
        $this->hydrate();

        $cid = $this->pendingSignal['client_order_id'] ?? null;
        if ($this->recovered || ! is_string($cid) || $cid === '') {
            return [];
        }

        return [$cid];
    }

    public function applyRecoveredOrder(ExchangeOrder $order): void
    {
        $this->hydrate();

        $meta = $this->pendingSignal;
        $cid = is_array($meta) ? ($meta['client_order_id'] ?? null) : null;
        if (is_array($meta) && is_string($cid) && $cid !== '' && $order->clientOrderId === $cid) {
            $this->resolveOrder($order, $meta);
        }

        // 一次性标志：本进程已执行过崩溃核实（独立于 pending 清理，避免重复入账）
        $this->recovered = true;
        $this->flushSnapshot();
    }

    // ------------------------------------------------------------------
    // Runner 接线（下单前绑定 clientOrderId，pitfall #37）
    // ------------------------------------------------------------------

    public function attachClientOrderId(string $clientOrderId, string $side, string $qty): void
    {
        $this->hydrate();

        $meta = $this->pendingSignal ?? $this->lastSignalMeta;
        if ($meta === null) {
            return;
        }

        if (isset($meta['client_order_id']) && $meta['client_order_id'] !== null) {
            return; // 单飞：已有在途 cid，拒绝重复绑定
        }

        $meta['client_order_id'] = $clientOrderId;
        $this->pendingSignal = $meta;
        $this->lastSignalMeta = $meta;

        // 冷却推进 + 记录最近信号 bar
        $this->lastSignalBar = is_int($meta['bar_time'] ?? null) ? $meta['bar_time'] : $this->currentBar;
        $this->cooldownRemaining = $this->program->risk->cooldownBars;

        $this->flushSnapshot();
    }

    /**
     * 暴露待执行/在途信号（供 HistoricalPreviewService 等领域内预览服务读取）。
     *
     * @return array<string,mixed>|null
     */
    public function pendingSignal(): ?array
    {
        return $this->pendingSignal;
    }

    // ------------------------------------------------------------------
    // 内部：求值与信号
    // ------------------------------------------------------------------

    private function evaluate(
        ?ExchangeKline $bar,
        ?ExchangeTicker $ticker,
        string $trigger,
        bool $entryBlocked = false,
    ): void {
        $snapshot = $this->snapshotBuilder->build($this->program, $this->recentBars, $ticker);

        $ctx = new DslEvalContext(
            tenantId: $this->tenantId(),
            instanceId: $this->instanceId,
            trigger: $trigger,
            bar: $bar,
            ticker: $ticker,
            recentBars: $this->recentBars,
            position: null,
            indicatorSnapshot: $snapshot,
            allocatedCapital: $this->allocatedCapital(),
            positionSnapshot: $this->positionSnapshot,
        );

        try {
            $result = $this->interpreter->evaluate($this->program, $ctx);
        } catch (DslExecutionException $e) {
            // 超限中断：记录且不静默（FR-004 / SC-009）
            Log::warning('DSL 求值被限额中断', [
                'instance' => $this->instanceId,
                'trigger' => $trigger,
                'cost_steps' => $e->costSteps,
                'limit_kind' => $e->limitKind,
            ]);

            return;
        }

        $this->applySignals($result->signals, $bar, $entryBlocked);
    }

    /**
     * @param DslSignal[] $signals
     */
    private function applySignals(array $signals, ?ExchangeKline $bar, bool $entryBlocked): void
    {
        foreach ($signals as $signal) {
            $kind = $this->kindOf($signal->action);
            if ($kind === null) {
                continue; // noop 不产信号
            }

            if ($kind === self::KIND_OPEN) {
                if ($entryBlocked || $this->cooldownRemaining > 0) {
                    continue; // 冷却闸门（risk.cooldown_bars）
                }
                if ($this->entriesThisBar >= $this->program->risk->maxEntriesPerBar) {
                    continue; // 单 bar 开仓上限（risk.max_entries_per_bar）
                }
                $this->entriesThisBar++;
            }

            $this->pendingSignal = [
                'section' => $signal->section,
                'action' => $signal->action,
                'kind' => $kind,
                'side' => $this->sideOf($signal->action),
                'qty' => $signal->qty,
                'qty_pct' => $signal->qtyPct,
                'rule_index' => $signal->ruleIndex,
                'note' => $signal->note,
                'client_order_id' => null,
                'bar_time' => $bar?->openTime,
            ];

            return; // 单飞：一次只挂一条待执行信号
        }
    }

    // ------------------------------------------------------------------
    // 内部：数量换算（bcmath，FR-009）
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $meta
     */
    private function resolveQuantity(array $meta): ?string
    {
        $qty = $meta['qty'] ?? null;
        if (is_string($qty) && $qty !== '') {
            return bccomp($qty, '0', 8) === 1 ? $qty : null; // 显式 qty 原样透传
        }

        $pct = $meta['qty_pct'] ?? null;
        if (! is_string($pct) || $pct === '') {
            return null;
        }

        if ($meta['kind'] === self::KIND_CLOSE) {
            if (bccomp($this->positionQty, '0', 8) !== 1) {
                return null;
            }
            $qty = bcmul($this->positionQty, $pct, 8);
        } else {
            $ref = $this->referencePrice();
            if ($ref === null || bccomp($ref, '0', 12) !== 1) {
                Log::warning('DSL qty_pct 开仓缺少参考价，不产出信号', [
                    'instance' => $this->instanceId,
                ]);

                return null;
            }
            $qty = bcdiv(bcmul($this->allocatedCapital(), $pct, 8), $ref, 8);
        }

        return bccomp($qty, '0', 8) === 1 ? $qty : null;
    }

    private function referencePrice(): ?string
    {
        if ($this->program->meta->trigger === 'on_tick' && $this->lastTicker !== null) {
            return $this->lastTicker->lastPrice;
        }

        if ($this->lastBar !== null) {
            return $this->lastBar->close;
        }

        return $this->lastTicker?->lastPrice;
    }

    private function withinMaxPosition(string $qty): bool
    {
        $maxPct = $this->program->risk->maxPositionPct;
        if ($maxPct === null) {
            return true;
        }

        $ref = $this->referencePrice();
        if ($ref === null) {
            return true;
        }

        $current = bcmul($this->positionQty, $ref, 12);
        $prospective = bcadd($current, bcmul($qty, $ref, 12), 12);
        $cap = bcmul($this->allocatedCapital(), $maxPct, 12);

        // 超出聚合上限即拒（不截断）
        return bccomp($prospective, $cap, 12) !== 1;
    }

    // ------------------------------------------------------------------
    // 内部：风控合成平仓（stop_loss_pct / take_profit_pct）
    // ------------------------------------------------------------------

    private function riskExitSignal(): ?array
    {
        if (bccomp($this->positionQty, '0', 8) !== 1 || $this->entryPrice === null) {
            return null;
        }

        $sl = $this->program->risk->stopLossPct;
        $tp = $this->program->risk->takeProfitPct;
        if ($sl === null && $tp === null) {
            return null;
        }

        $mark = $this->referencePrice();
        if ($mark === null || bccomp($this->entryPrice, '0', 12) !== 1) {
            return null;
        }

        // 持仓收益率（bcmath 12 位；空头反向）
        $pct = bcdiv(bcsub($mark, $this->entryPrice, 12), $this->entryPrice, 12);
        if ($this->direction === 'short') {
            $pct = bcmul($pct, '-1', 12);
        }

        $reason = null;
        if ($sl !== null && bccomp($pct, bcmul($sl, '-1', 12), 12) <= 0) {
            $reason = 'stop_loss';
        } elseif ($tp !== null && bccomp($pct, $tp, 12) >= 0) {
            $reason = 'take_profit';
        }

        if ($reason === null) {
            return null;
        }

        $isShort = $this->direction === 'short';
        $side = $isShort ? 'buy' : 'sell';

        $this->pendingSignal = [
            'section' => 'exit',
            'action' => $isShort ? 'close_short' : 'close_long',
            'kind' => self::KIND_CLOSE,
            'side' => $side,
            'qty' => $this->positionQty,
            'qty_pct' => null,
            'rule_index' => -1,
            'note' => $reason,
            'client_order_id' => null,
            'bar_time' => $this->currentBar,
        ];
        $this->lastSignalMeta = $this->pendingSignal;
        $this->flushSnapshot();

        return ['side' => $side, 'qty' => $this->positionQty, 'price' => null];
    }

    // ------------------------------------------------------------------
    // 内部：成交结算
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $meta
     */
    private function resolveOrder(ExchangeOrder $order, array $meta): void
    {
        $status = $order->status;
        if ($status === 'filled') {
            $this->applyFill($order, $meta);
        } elseif (! in_array($status, ['canceled', 'rejected', 'failed'], true)) {
            return; // submitted/partial：订单仍有效，保持挂起
        }

        // filled 已入账；其余终态（含查无合成的 failed）释放占位
        $this->pendingSignal = null;
        $this->lastSignalMeta = null;
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function applyFill(ExchangeOrder $order, array $meta): void
    {
        $filled = bccomp($order->filledQty, '0', 8) === 1 ? $order->filledQty : $order->quantity;
        $price = (string) ($order->avgFillPrice ?? '0');

        if ($meta['kind'] === self::KIND_OPEN) {
            $isFirst = bccomp($this->positionQty, '0', 8) !== 1;
            $this->positionQty = $this->normalizeQty(bcadd($this->positionQty, $filled, 8));
            $this->direction = $meta['action'] === 'open_short' ? 'short' : 'long';
            if ($isFirst) {
                $this->entryPrice = $price;
            }

            return;
        }

        // 平仓：实现盈亏（usdt，12 位）
        if ($this->entryPrice !== null) {
            $delta = $this->direction === 'short'
                ? bcsub($this->entryPrice, $price, 12)
                : bcsub($price, $this->entryPrice, 12);
            $this->realizedPnl = bcadd($this->realizedPnl, bcmul($delta, $filled, 12), 12);
        }

        $this->positionQty = $this->normalizeQty(bcsub($this->positionQty, $filled, 8));
        if (bccomp($this->positionQty, '0', 8) !== 1) {
            $this->positionQty = '0';
            $this->direction = null;
            $this->entryPrice = null;
        }
    }

    /**
     * 持仓数量规范化：抹去小数尾随零（bcmath scale 8 产出），保持快照可读且与 '0' 默认一致。
     */
    private function normalizeQty(string $qty): string
    {
        if (! str_contains($qty, '.')) {
            return $qty;
        }

        $trimmed = rtrim(rtrim($qty, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }

    // ------------------------------------------------------------------
    // 内部：快照映射
    // ------------------------------------------------------------------

    private function flushSnapshot(): void
    {
        // 合并写入：保留迁移前既有键（v1→v2 不静默丢弃，constitution IV）
        $snap = is_array($this->positionSnapshot) ? $this->positionSnapshot : [];
        $snap['dsl'] = [
            'compiled_hash' => $this->compiledHash,
            'last_signal_bar' => $this->lastSignalBar,
            'cooldown_remaining' => $this->cooldownRemaining,
            'pending_signal' => $this->pendingSignal,
            'recovered' => $this->recovered,
        ];
        $snap['position'] = [
            'qty' => $this->positionQty,
            'direction' => $this->direction,
            'entry_price' => $this->entryPrice,
        ];

        $this->positionSnapshot = $snap;
    }

    private function hydrate(): void
    {
        if ($this->hydrated) {
            return;
        }
        $this->hydrated = true;

        $snap = is_array($this->positionSnapshot) ? $this->positionSnapshot : [];

        $dsl = is_array($snap['dsl'] ?? null) ? $snap['dsl'] : [];
        $this->lastSignalBar = isset($dsl['last_signal_bar']) ? (int) $dsl['last_signal_bar'] : null;
        $this->cooldownRemaining = (int) ($dsl['cooldown_remaining'] ?? 0);
        $this->pendingSignal = is_array($dsl['pending_signal'] ?? null) ? $dsl['pending_signal'] : null;
        $this->recovered = (bool) ($dsl['recovered'] ?? false);

        $pos = is_array($snap['position'] ?? null) ? $snap['position'] : [];
        $this->positionQty = (string) ($pos['qty'] ?? '0');
        $this->direction = isset($pos['direction']) && $pos['direction'] !== null ? (string) $pos['direction'] : null;
        $this->entryPrice = isset($pos['entry_price']) && $pos['entry_price'] !== null
            ? (string) $pos['entry_price']
            : null;
    }

    /**
     * v1 → v2 迁移：v1 快照无 `dsl`/`position` 扩展键，按空默认升级（不静默丢弃既有字段）。
     *
     * @param array<string,mixed>|null $snapshot
     *
     * @return array<string,mixed>
     */
    private function migrateV1Snapshot(?array $snapshot): array
    {
        $snapshot ??= [];
        $snapshot['dsl'] ??= [
            'compiled_hash' => $this->compiledHash,
            'last_signal_bar' => null,
            'cooldown_remaining' => 0,
            'pending_signal' => null,
            'recovered' => false,
        ];
        $snapshot['position'] ??= ['qty' => '0', 'direction' => null, 'entry_price' => null];

        return $snapshot;
    }

    // ------------------------------------------------------------------
    // 内部：小工具
    // ------------------------------------------------------------------

    private function hasInFlightOrder(): bool
    {
        $cid = $this->pendingSignal['client_order_id'] ?? null;

        return is_string($cid) && $cid !== '';
    }

    private function kindOf(string $action): ?string
    {
        return match ($action) {
            'open_long', 'open_short' => self::KIND_OPEN,
            'close_long', 'close_short' => self::KIND_CLOSE,
            default => null,
        };
    }

    private function sideOf(string $action): string
    {
        return match ($action) {
            'open_long', 'close_short' => 'buy',
            default => 'sell',
        };
    }

    private function tenantId(): int
    {
        return (int) ($this->params['tenant_id'] ?? $this->context->params['tenant_id'] ?? 0);
    }

    private function allocatedCapital(): string
    {
        foreach ([$this->params['allocated_capital'] ?? null, $this->context->params['allocated_capital'] ?? null] as $candidate) {
            if (is_numeric($candidate) && bccomp((string) $candidate, '0', 12) === 1) {
                return (string) $candidate;
            }
        }

        return '0';
    }

    /**
     * 装载期校验：`meta.symbol` MUST 与实例 symbol 一致（FR-005）。
     *
     * @throws DslValidationException
     */
    private function assertSymbolMatches(): void
    {
        $instanceSymbol = $this->params['symbol'] ?? $this->context->params['symbol'] ?? null;
        if (! is_string($instanceSymbol) || $instanceSymbol === '') {
            return; // 未注入实例 symbol（如纯逻辑单测）：无法核对，交由运行期
        }

        if ($this->program->meta->symbol !== $instanceSymbol) {
            throw new DslValidationException([
                new DslDiagnostic(
                    level: 'error',
                    line: 1,
                    field: 'symbol',
                    message: sprintf(
                        'DSL meta.symbol「%s」与实例 symbol「%s」不一致，拒绝装载',
                        $this->program->meta->symbol,
                        $instanceSymbol,
                    ),
                ),
            ]);
        }
    }

    private function resolveBufferSize(): int
    {
        $needed = $this->snapshotBuilder->requiredWindow($this->program);

        return max($needed, 2);
    }
}
