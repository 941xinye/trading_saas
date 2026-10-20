# Contract: BacktestRunner 驱动核心契约

**Feature**: specs/008-backtest-engine | **依据**: research.md R-001/R-002/R-003

## 1. BacktestRunner（Domain\Backtest\Services）

回测唯一驱动入口。纯同步内存执行，**无副作用**（不写 DB/Redis/不调交易所）；持久化由调用方（RunBacktestJob）负责。

```php
final class BacktestRunner
{
    public function __construct(
        private HistoricalQuery $historical,
        private StrategyRegistry $registry,
        private MatchingEngine $matcher,
        private EquityCurveBuilder $equity,
    ) {}

    /**
     * @throws BacktestDataCoverageException  覆盖率 < config('backtest.min_coverage_pct')
     * @throws BacktestStrategyNotImplemented 策略码不在白名单（FR-001）
     * @throws ArchiveStorageUnavailableException 历史层不可用（原样上抛）
     */
    public function run(BacktestRunRequest $request): BacktestRunResult;

    /** 进度回调（Job 注入，节流写 progress_pct）；同步内存模式可为 null */
    public function onProgress(?\Closure $cb): self;
}
```

### 行为契约（实现 MUST 遵守）

1. **策略构造唯一入口** = `$this->registry->make($code, 0, $params, $freshContext)`；**禁止** `new SpotGridStrategy()` 等任何具体策略类直接构造（FR-003；BacktestNoBypassTest 静态扫描 Domain\Backtest 目录守护）
2. **回放循环**：HistoricalQuery 分页（pageSize=config archive.query_page_size）→ 逐 bar：
   - `$strategy->onBar($bar)`
   - `$decision = $strategy->shouldOpen($ctx) ?? $strategy->shouldClose($ctx)`（返回 `['side','qty','price']` 关联数组）
   - 非 null → `$this->matcher->fill($decision, $bar, $assumptions)` 产出 SimulatedTrade（含 fee）
   - `$strategy->onFill($exchangeOrder)`（用 Matcher 产出构造 ExchangeOrder DTO，对齐实跑回调语义）
   - `$this->equity->mark($strategy, $bar)` 记录净值点
3. **上下文**：StrategyContext 每次新建空上下文（持仓/盈亏由策略自身 positionSnapshot 维护，与 HistoricalPreviewService 先例一致）；回测 instanceId=0（无 Redis 状态读写——策略 restoreState 不调用）
4. **覆盖率**：回放完成后 barCount / 理论 bar 数（区间秒数 ÷ interval 秒数）< 阈值 → 抛 BacktestDataCoverageException（FR-013；**不静默截断**）
5. **零交易所调用**：Runner 依赖注入面不含任何 ExchangeAdapter（FR-005 POC 断言用静态扫描 + RecordingAdapter 双保险）

## 2. MatchingEngine（撮合）

```php
final class MatchingEngine implements SimulatedMatcher
{
    /**
     * 成交价：buy = close × (1 + bps/10000)；sell = close × (1 − bps/10000)
     * fee = fillPrice × qty × feeRate（bcmath：中间 12 位，落账 8 位）
     * @return SimulatedTrade（qty/price/fee 均 bcmath 字符串）
     */
    public function fill(array $decision, ExchangeKline $bar, MatchingAssumptions $a): SimulatedTrade;
}
```

- decision['qty'] 为 0/负 → 抛 InvalidArgumentException（策略 bug，不静默）
- futures 方向语义：不做特殊分支——多空入账由策略 onFill 内部处理（Runner 只透传含 fee 的 ExchangeOrder）；保证金占用不建模（净值曲线按 realizedPnl + 持仓估值，对齐 preview 范式）

## 3. EquityCurveBuilder

```php
final class EquityCurveBuilder
{
    /** 净值 = initialCapital + realizedPnl + 未实现盈亏（持仓 qty × 方向 × (close − entry)，bcmath 12 位） */
    public function mark(StrategyInterface $strategy, ExchangeKline $bar): EquityPoint;

    /** @return EquityPoint[] */
    public function points(): array;
}
```

- 持仓读取：`$strategy->getState()->positionSnapshot`（策略统一状态出口，FR-003 推论：不读私有属性）

## 4. DTO（均 readonly，一文件一类）

- `BacktestRunRequest`：tenantId / strategyCode / params（含 dsl_script 或实例参数）/ symbol / interval / fromUtc / toUtc / assumptions / pageSize?
- `MatchingAssumptions`：feeRate / slippageBps / initialCapital / narrative（口径说明文案，报告展示）
- `SimulatedTrade`：seq / barOpenTime / side / qty / price / fee / realizedPnl
- `EquityPoint`：barTime / equity（字符串）
- `BacktestRunResult`：barCount / trades[] / equityPoints[] / coveragePct
