# Contracts: 自定义 DSL 策略引擎（P7）

> spec 006 plan 阶段接口契约。复用既有 `StrategyInterface` / `OrderExecutor` / `IndicatorCalculator` / `HistoricalQuery` 接口面，不修改其签名；新增 DSL 解析、AST、解释器、预览与编辑器校验契约。全部求值路径为纯 PHP 白名单解释（FR-003/FR-017）。

## 1. 既有契约复用（无修改）

| 契约 | 复用点 | 与 DSL 的关系 |
|---|---|---|
| `StrategyInterface`（`app/Domain/Strategy/Contracts/StrategyInterface.php`） | `__construct(int $instanceId, array $params, StrategyContext $context)` / `onTick` / `onBar` / `onFill` / `shouldOpen(StrategyContext): ?array`（`[side,qty,price]`） / `shouldClose` / `getState(): StrategyState` / `restoreState(StrategyState)` | `DslStrategy` 全量实现（FR-006） |
| `AbstractStrategy` | `getState/restoreState` 骨架，`protected const SCHEMA_VERSION = 1` | DSL 覆写并升至 2 |
| `StrategyContext` | `instanceId, params, ?ExchangePosition position, ?ExchangeKline positionKline, array recentBars, ?ExchangeTicker ticker` | 解释器数据源（`recentBars`） |
| `StrategyState` | `schemaVersion, params, positionSnapshot, realizedPnl, consecutiveLosses, lastBarTime`；snake_case 序列化 | `positionSnapshot.dsl` 承载扩展状态 |
| `RecoverablePendingOrders` | `pendingClientOrderIds(): array` / `applyRecoveredOrder(ExchangeOrder)` | `DslStrategy` 实现（崩溃恢复） |
| `OrderExecutor::place(int $tenantId, object $instance, ExchangeAdapter $adapter, array $decision)` | `$decision = {side, qty, price?, client_order_id?, leverage?, reduce_only?}` | DSL 信号经此下单（bcmath 字符串 qty） |
| `OrderExecutor::generateClientOrderId(int $tenantId, int $instanceId): string` | 模板 `{tenant_id}_{instance_id}_{timestamp}_{random}` | 幂等锚点不变（constitution II） |
| `IndicatorCalculator::compute(array $bars, ?array $config = null): array` | 返回 `close/ma/rsi/macd/boll/atr`，NaN→null | 指标唯一来源（FR-008） |
| `HistoricalQuery::query(HistoricalQueryRequest): HistoricalQueryResult` | `query(HistoricalQueryRequest)` | 预览数据源（FR-012） |
| `HistoricalQueryRequest(ArchiveDataType, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, ?string $symbol, ?string $exchangeCode, ?string $interval, ?int $pageSize, ?string $cursor)` | `[from,to)` UTC | 预览分页入参 |
| `HistoricalQueryResult` | `rows: list<array<string,string|null>>`, `?nextPageCursor`, `servedFrom` | 预览行 → `ExchangeKline` |

## 2. 新增：DSL 解析链契约

```php
namespace App\Domain\Strategy\Custom\Dsl;

/**
 * 受限 YAML 子集解析器（§3 语法）。仅支持块映射/块序列/plain 标量/注释/2 空格缩进；
 * 预扫描拒绝 锚点/别名/tag/多文档/flow/Tab 缩进/重复键（抛 DslValidationException）。
 */
final class DslYamlParser
{
    /**
     * @return array<string,mixed> 嵌套 PHP 数组（mapping/sequence/scalar）
     * @throws DslValidationException 携带 DslDiagnostic[]（含行号）
     */
    public function parse(string $script): array;
}
```

```php
namespace App\Domain\Strategy\Custom;

use App\Domain\Strategy\Custom\Dsl\DslProgram;
use App\Domain\Strategy\Custom\Dsl\DslValidationException;

/**
 * DSL 解析门面（填充既有占位类）。编排：预扫描 → DslYamlParser → DslCompiler。
 * 注：将既有 `parse(): array` 收窄为类型化 `DslProgram`（PSR-4/类型安全），旧返回结构不再暴露。
 */
final class DslParser
{
    /**
     * @throws DslValidationException 非法 DSL（FR-002/FR-005），诊断含 行/字段
     */
    public function parse(string $script): DslProgram;
}
```

```php
namespace App\Domain\Strategy\Custom\Dsl;

/**
 * 结构校验 + AST 构建（顶层键白名单/四段/枚举/类型/范围）。
 * error 级诊断 ⇒ 抛 DslValidationException（FR-005）。
 */
final class DslCompiler
{
    /**
     * @param array<string,mixed> $raw        DslYamlParser 输出
     * @param string              $sourceHash sha1(原始脚本)
     * @throws DslValidationException
     */
    public function compile(array $raw, string $sourceHash): DslProgram;
}
```

## 3. 新增：AST 契约（不可变，见 data-model.md §2）

```php
namespace App\Domain\Strategy\Custom\Dsl;

final readonly class DslProgram
{
    /** @param RuleNode[] $filters @param RuleNode[] $entries @param RuleNode[] $exits */
    public function __construct(
        public int $version,
        public DslMeta $meta,
        public array $filters,
        public array $entries,
        public array $exits,
        public DslRisk $risk,
        public string $sourceHash,
    ) {}
}

final readonly class DslMeta
{
    public function __construct(
        public string $name,
        public string $symbol,
        public string $interval,
        public string $direction,   // long|short|both
        public string $trigger,     // on_bar|on_tick|on_fill
    ) {}
}

final readonly class RuleNode
{
    public function __construct(
        public string $action,            // 5 动作枚举
        public ConditionNode $condition,
        public ?string $qty = null,       // base 数量（bcmath 字符串，原样透传）
        public ?string $qtyPct = null,    // 分数；开仓=资金名义额比例(÷refPrice)，平仓=持仓数量比例（§6）
        public ?string $note = null,
    ) {}
}

final readonly class ConditionNode
{
    /** @param ConditionNode[] $operands */
    public function __construct(
        public string $type,              // comparison|logical|cross|always
        public ?string $op = null,        // gt|gte|.../and|or|not/cross_above|...
        public ?OperandNode $left = null,
        public ?OperandNode $right = null,
        public array $operands = [],
        public ?ConditionNode $operand = null,
    ) {}
}

final readonly class OperandNode
{
    public function __construct(
        public string $type,              // indicator|price|const
        public ?string $indicator = null, // ma|rsi|macd|bollinger|atr
        public ?int $period = null,
        public ?string $source = null,    // macd: dif|dea|hist
        public ?string $band = null,      // bollinger: upper|middle|lower
        public ?string $stdDev = null,
        public ?string $price = null,     // open|high|low|close|last
        public ?string $const = null,     // 数字字符串
    ) {}
}

final readonly class DslRisk
{
    public function __construct(
        public ?string $maxPositionPct = null,
        public int $cooldownBars = 0,
        public ?string $stopLossPct = null,
        public ?string $takeProfitPct = null,
        public int $maxEntriesPerBar = 1,
    ) {}
}
```

## 4. 新增：诊断与异常契约

```php
namespace App\Domain\Strategy\Custom\Dsl;

final readonly class DslDiagnostic
{
    public function __construct(
        public string $level,    // error|warning
        public int $line,        // 源脚本行号（1 起）
        public string $field,    // 定位路径，如 entry.0.when.op
        public string $message,  // 中文可读信息
    ) {}
}

final class DslValidationException extends \RuntimeException
{
    /** @param DslDiagnostic[] $diagnostics */
    public function __construct(public readonly array $diagnostics)
    {
        parent::__construct($this->buildMessage($diagnostics)); // message 由 error 级诊断拼接
    }
    /** @return DslDiagnostic[] */
    public function diagnostics(): array;
}
```

| 场景 | 处理 | 依据 |
|---|---|---|
| 未知顶层键 / 未知 op / 未知指标 / 未知动作 | `error` 诊断 + 拒绝编译 | FR-002 |
| 语法错误 / 类型不匹配 / 范围越界（period≤0、qty≤0、分式不在 (0,1]） | `error` 诊断 + 拒 | spec Edge / FR-005 |
| 超限（字节/行/深度/规则数/指标数） | `error` 诊断 + 拒 | FR-004 |
| 版本高于支持 | `error` 诊断 + 拒（装载期） | spec Edge |

## 5. 新增：沙箱解释器契约

```php
namespace App\Domain\Strategy\Custom\Dsl;

interface DslInterpreter
{
    /**
     * 白名单求值：输出信号 + 消耗步数。绝不 eval/exec/反射/IO（FR-003）。
     * 指标快照仅计算一次（§5 data-model）；NaN/null ⇒ 对应条件 false。
     * @throws DslExecutionException 超 step/time budget（不静默）
     */
    public function evaluate(DslProgram $program, DslEvalContext $context): DslEvalResult;
}
```

**实现**：`App\Domain\Strategy\Custom\Dsl\WhitelistInterpreter`（纯 PHP，判别式 AST 的 `match`）。

```php
namespace App\Domain\Strategy\Custom\Dsl;

final readonly class DslEvalContext
{
    /** @param ExchangeKline[] $recentBars @param array<string,mixed>|null $positionSnapshot */
    public function __construct(
        public int $tenantId,
        public int $instanceId,
        public string $trigger,
        public ?ExchangeKline $bar,
        public ?ExchangeTicker $ticker,
        public array $recentBars,
        public ?ExchangePosition $position,
        public array $indicatorSnapshot,
        public string $allocatedCapital,
        public ?array $positionSnapshot = null,
    ) {}
}

final readonly class DslEvalResult
{
    /** @param DslSignal[] $signals */
    public function __construct(public array $signals, public int $costSteps) {}
}

final readonly class DslSignal
{
    public function __construct(
        public string $section,     // filter|entry|exit
        public int $ruleIndex,
        public string $action,      // 动作枚举
        public ?string $qty = null, // bcmath 字符串（qty_pct 已换算）
        public ?string $qtyPct = null,
        public ?string $note = null,
    ) {}
}
```

## 6. DslStrategy 生命周期映射（FR-006）

`DslStrategy extends AbstractStrategy implements RecoverablePendingOrders`（`app/Domain/Strategy/Custom/DslStrategy.php`，填充占位）。

| 方法 | DSL 语义 |
|---|---|
| `__construct(int $instanceId, array $params, StrategyContext $context)` | 读取 `params['dsl_script']` → `DslParser::parse()` → 持有 `DslProgram`；非法即抛 `DslValidationException`（不进入运行态，FR-005） |
| `onTick(ExchangeTicker)` | 仅 `trigger=on_tick` 时有意义；命中信号挂到 `pending_signal`（不写库/Redis，接口约束） |
| `onBar(ExchangeKline)` | 计算指标快照 → `DslInterpreter::evaluate()` → 命中 `entry`/`exit` 信号挂 `pending_signal`；执行 `risk` 段（冷却、`max_position_pct`、止损/止盈合成条件） |
| `onFill(ExchangeOrder)` | 更新内存持仓快照与 `realizedPnl`；清理已兑现的 `pending_signal` |
| `shouldOpen(StrategyContext): ?array` | 依据 `pending_signal`（open_long/open_short）返回 `[side, qty, price=null]`；`qty_pct` 开仓 → base qty = `bcdiv(bcmul(allocatedCapital, qtyPct, 8), refPrice, 8)`（`refPrice` = bar.close / ticker.last；不可得则不产出）；冷却/风控拦截时返回 null（FR-013：熔断 OPEN 下开仓拦截） |
| `shouldClose(StrategyContext): ?array` | close_long/close_short 返回 `[side, qty, price=null]`；`qty_pct` 平仓 → base qty = `bcmul(positionQty, qtyPct, 8)`；平仓**永远放行**（FR-013 / constitution V） |
| `getState(): StrategyState` | `schemaVersion=2`；`positionSnapshot['dsl'] = {compiled_hash,last_signal_bar,cooldown_remaining,pending_signal,recovered}` |
| `restoreState(StrategyState)` | schemaVersion 高版本拒绝；v1→v2 迁移分支；校验 `compiled_hash` 与当前脚本一致（不一致记 warning） |
| `pendingClientOrderIds(): array` | 返回 `pending_signal.client_order_id` 列表 |
| `applyRecoveredOrder(ExchangeOrder)` | 复用 P5.5 语义（filled 入账 / 其余终态释放占位） |

**SCHEMA_VERSION**：`protected const SCHEMA_VERSION = 2;`（覆盖 `AbstractStrategy` 的 1）。

## 7. 新增：回测预览契约（FR-012 / SC-006）

```php
namespace App\Domain\Strategy\Custom\Dsl;

interface DslPreviewService
{
    /**
     * 复用 P8 HistoricalQuery(KlineBar) 分页回放单条 DSL，
     * 与实跑共用同一 DslInterpreter + IndicatorCalculator（SC-006）。
     */
    public function preview(
        int $tenantId,
        string $script,
        string $symbol,
        string $interval,
        \DateTimeImmutable $fromUtc,
        \DateTimeImmutable $toUtc,
        ?int $pageSize = null,
    ): DslPreviewResult;
}

final readonly class DslPreviewResult
{
    /** @param array $signals @param array $equityCurve */
    public function __construct(
        public array $signals,        // [{barTime,action,qty,ruleIndex}]
        public array $equityCurve,    // [{barTime,equity}] 无滑点/手续费
        public int $barCount,
        public int $signalCount,
        public string $triggerRate,   // signalCount / barCount
        public string $assumption,    // "未模拟滑点/手续费"
        public ?string $nextPageCursor = null,
    ) {}
}
```

**实现**：`App\Domain\Strategy\Custom\Dsl\Preview\HistoricalPreviewService`。
**分页**：默认窗口 30 天；`pageSize` 取 config；`HistoricalQueryRequest` 每次以 `cursor` 续页，`HistoricalQueryResult.rows`（列名 => 字符串/null）→ `ExchangeKline` 后逐 bar 回放。

## 8. 新增：编辑器校验契约（FR-011 / FR-016）

```php
namespace App\Domain\Strategy\Custom\Dsl;

/** 编辑器即时校验（保存/试装载共用），仅收集诊断，不启动实例。 */
interface DslValidator
{
    /** @return DslDiagnostic[] 空数组 = 通过 */
    public function validate(string $script): array;
}
```

| Filament 交互 | 契约 | 依据 |
|---|---|---|
| 即时校验 | `DslValidator::validate()` → 带 行/字段 诊断 | US4-1 |
| 试装载 | `DslParser::parse()` 成功即通过（仅编译，不启动） | FR-011/US4 |
| 保存草稿 | 写 `strategy_instances.params.dsl_script`（实例级快照） | spec Assumptions |
| 租户隔离 | Resource 走 `TenantScope`；跨租户不可见/不可引用 | FR-016/US4-3 |

## 9. 配置契约

`config('strategy.dsl')`（data-model.md §6.1）：`max_script_bytes / max_lines / max_depth / max_rules_per_section / max_indicators / eval_step_budget / indicator_window_max / allowed_triggers / allowed_actions / allowed_indicators`。

`config('strategy.implemented')` 追加 `custom_dsl`；`config('plans.plans.{pro,enterprise}.features.strategies')` 追加 `custom_dsl`（free 不变）。

## 10. Worked Example：MA 金叉开多（合法脚本）

> 受限子集为**块式写法**（flow `{...}`/`[...]` 被预扫描拒绝）；示例与 `tests/Unit/Strategy/Dsl/DslParserTest.php` 一致。

```yaml
version: 1
name: "MA 金叉开多"
symbol: "BTCUSDT"
interval: "1m"
direction: long
trigger: on_bar

filter:
  # 前置闸门：收盘价 > 0（恒真示例，可换成趋势过滤）
  - action: noop
    when:
      op: gt
      left:
        price: close
      right:
        const: "0"

entry:
  - action: open_long
    when:
      op: cross_above
      left:
        indicator: ma
        period: 7
      right:
        indicator: ma
        period: 25
    qty_pct: "0.30"
    note: "短均线上穿长均线开多"

exit:
  - action: close_long
    when:
      op: cross_below
      left:
        indicator: ma
        period: 7
      right:
        indicator: ma
        period: 25
    qty_pct: "1.0"
    note: "死叉平多"

risk:
  max_position_pct: "0.50"
  cooldown_bars: 1
  stop_loss_pct: "0.02"
  take_profit_pct: "0.05"
```

**编译结果要点**：`meta.trigger=on_bar`；`entries` 为 1 条 `cross_above`（operand=ma7/ma25，复用内置 `prev`）；`exits` 为 `cross_below`；`qty_pct` 触发时换算 `bcmul(allocatedCapital, "0.30", 8)`；`compiled_hash = sha1(原始脚本)`。
