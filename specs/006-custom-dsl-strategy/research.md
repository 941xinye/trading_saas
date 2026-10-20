# Research: 自定义 DSL 策略引擎（P7）

> spec 006 plan 阶段研究。目标：确认 DSL 语法载体选型、受限 YAML 解析方案、结构化条件模型、沙箱求值边界、指标复用与数值域、内建策略等价口径、回测预览复用 P8 的可行性，为 data-model / contracts / plan 提供决策依据。所有决策回溯 spec.md FR-001~017 / SC-001~010 / US1~US5。

## 1. 语法载体选型：仅 YAML 四段声明式

**Decision**：本期唯一载体为 YAML，顶层四段 `entry`/`exit`/`filter`/`risk` + 元信息（name/symbol/interval/direction/trigger/version）；TOML 明确不在本期范围。

**Rationale**：
- spec Assumptions 与决策基线 ① 已收窄 README 的「YAML/TOML」为 YAML，本期只维护一份解析器，缩小攻击面与维护面（FR-002/FR-017）。
- 四段声明式与内置策略的心智模型对齐（entry/exit/filter/risk 是网格/DCA 决策的可表达子集），是 SC-002 语义等价的前提。

**Alternatives**：
| 备选 | 结论 | 理由 |
|---|---|---|
| 仅 YAML | 采纳 | 决策基线 ①；单解析器 |
| YAML + TOML | 拒绝 | 需第二套解析器；本期无 TOML 需求 |
| 表达式语言（类 Pine/自研 tokenizer） | 拒绝 | 引入字符串表达式求值面，触碰 FR-003/FR-017 红线 |

## 2. YAML 解析方案：内部受限子集解析器（DslYamlParser）

**Decision**：自研受限子集解析器 `DslYamlParser`，**不使用** `symfony/yaml`。

**Rationale**：
- `symfony/yaml` 未在当前 vendor 中，新增第三方依赖被拒（决策基线 ②）。这同时符合 FR-017 的取向——不把求值/解析路径交给第三方扩展。
- 完整 YAML 的表达能力（锚点/别名/tag/多文档/flow/合并键）远超 DSL 入参面，反而扩大解析期攻击面（spec Edge：锚点别名爆炸、超深嵌套、超长字符串）。

**支持的子集（白名单，只有这些）**：

| 语法元素 | 支持 | 说明 |
|---|---|---|
| 块映射（`key: value`） | 是 | 顶层与嵌套 mapping |
| 块序列（`- ` 起行） | 是 | filter/entry/exit 为规则列表 |
| 标量 | 是 | 无引号 plain scalar + 双/单引号字符串；int/float/bool/null 由编译器按上下文转型 |
| 注释 | 是 | 行内 `#` 起注释（引号内 `#` 不算注释） |
| 缩进 | 是 | 2 空格及其整数倍；同一层级缩进必须一致 |
| 空行 | 是 | 忽略 |

**预扫描显式拒绝（在递归构建前 fail-fast，拒绝即给出定位）**：

| 特征 | 记号 | 拒绝理由 |
|---|---|---|
| 锚点 / 别名 | `&` / `*` | 别名爆炸内存 DoS |
| 标签 | `!` | 未知类型语义，超出入参面 |
| 多文档 | `---` / `...` | 本期单文档 |
| flow 风格 | `{` `}` `[` `]` | 引入第二套词法，扩大面 |
| Tab 缩进 | `\t` | 缩进歧义 |
| 重复键 | 同层同 key | 静默覆盖风险（FR-002 禁止静默） |

**规模上限（解析前，字节/行级，FR-004）**：`max_script_bytes`（默认 8192）、`max_lines`（默认 200）。

**解析流水线**：
```
原始脚本 → 预扫描（拒绝记号 + 字节/行上限）
        → 逐行词法（缩进栈 + 注释剥离）
        → 构建嵌套 PHP 数组（mapping/sequence/scalar）
        → DslCompiler 结构校验 + AST 构建（行/字段级诊断）
```

**Alternatives**：
| 备选 | 结论 | 理由 |
|---|---|---|
| 自研受限子集 | 采纳 | 无新依赖；面最小；定位可控 |
| `symfony/yaml` | 拒绝 | 未 vendored；新增依赖被拒 |
| PHP `yaml` 扩展（libyaml） | 拒绝 | 环境依赖不确定（坑 #23 类比） |

## 3. 条件模型：结构化映射，无表达式/ tokenizer 语法

**Decision**：条件为带 `op` 的 mapping；操作数为 mapping；**不存在**字符串表达式语法，无 tokenizer、无运算符优先级解析（FR-002/FR-017）。

**Rationale**：
- mapping 形式的每个字段都可精确定位（行 + 字段名），直接支撑编辑器诊断（US4）与装载拒绝（FR-005）。
- 无字符串表达式 ⇒ 无 eval/表达式引擎攻击面，AST 判别天然白名单（§4）。
- AST 节点显式 `type` 判别，求值即 match，无需二次解析。

**Grammar（条件映射，恰好其一）**：

| 形式 | 形状 | 语义 |
|---|---|---|
| 比较 | `{op: gt\|gte\|lt\|lte\|eq\|ne, left, right}` | 当前值二元比较（float 域） |
| 交叉 | `{op: cross_above\|cross_below, left, right}` | 当前值与上一根 bar 值穿越判定 |
| 逻辑 | `{op: and\|or, operands: [cond,...]}` / `{op: not, operand: cond}` | 布尔组合 |
| 恒真 | `{op: true}` | 恒成立（供周期触发/DCA 等价） |

**Grammar（操作数映射，恰好其一）**：

| 形式 | 形状 | 取值 |
|---|---|---|
| 指标 | `{indicator: ma, period: 7}` | 复用 `IndicatorCalculator` 输出 |
| 指标 | `{indicator: rsi, period: 14}` | 同上 |
| 指标 | `{indicator: macd, source: dif\|dea\|hist}` | 同上 |
| 指标 | `{indicator: bollinger, band: upper\|middle\|lower, period: 20, std_dev: 2}` | 同上 |
| 指标 | `{indicator: atr, period: 14}` | 同上 |
| 价格 | `{price: open\|high\|low\|close\|last}` | `last` 取最新 ticker/tick |
| 常量 | `{const: "123.45"}` | 字符串，float 域比较（仅信号数学） |

**Alternatives**：字符串表达式（`ma(7) > ma(25)`）需 tokenizer + 优先级 + 求值器，三倍攻击面，且定位粒度差 → 拒绝。

## 4. 沙箱求值：纯 PHP 白名单解释器

**Decision**：求值路径为纯 PHP 白名单解释器 `DslInterpreter`，绝不 `eval`/`exec`/`include`/反射/文件/网络/shell（FR-003 红线，不可协商；FR-017）。

**强制机制**：
- AST 节点类型为判别式（comparison/logical/cross/always、indicator/price/const），解释器只 `match` 白名单原语 —— 结构上不存在动态调用路径。
- 规模与成本上限（FR-004）：每 bar 求值 `eval_step_budget`（默认 500 步）+ 每 bar 时间预算；超限即中断并记录，不静默，不影响行情协程推进（§10 风险）。
- 解析期与运行期两处红线（SC-003）：未知 `op`/未知指标/未知动作/未知顶层键在解析期拒；运行期对任何未被 AST 覆盖的分支抛 `DslExecutionException`。

**红线语料库（SC-003）**：含 `eval`/`exec`/`system`/文件读写/网络/反射/未知原语的脚本，在解析与运行两处 100% 被拒。

**Alternatives**：
| 备选 | 结论 | 理由 |
|---|---|---|
| 纯 PHP 白名单解释器 | 采纳 | FR-003/FR-017；可审计 |
| eval 编译为 PHP 闭包 | 拒绝 | 触碰 FR-003 红线 |
| 第三方表达式引擎（symfony/expression-language 等） | 拒绝 | FR-017；未 vendored；动态求值面 |

## 5. 指标复用与数值域

**Decision**：指标一律复用既有 `IndicatorCalculator::compute(array $bars, ?array $config): array`（FR-008），不另起算法；float 域**仅**用于条件判断，流向 `OrderExecutor` 的 qty/price **一律 bcmath 字符串**（constitution II / FR-009）。

**指标输出 ↔ DSL 操作数映射**：

| DSL 操作数 | IndicatorCalculator 字段 | 是否含 prev |
|---|---|---|
| `{indicator: ma, period: p}` | `ma[p] = {v, prev}` | 是（内置 prev） |
| `{indicator: rsi, period: 14}` | `rsi`（float\|null） | 否 |
| `{indicator: macd, source: dif}` | `macd.dif` | 否 |
| `{indicator: macd, source: dea}` | `macd.dea` | 否 |
| `{indicator: macd, source: hist}` | `macd.hist` / `macd.hist_prev` | 是（hist） |
| `{indicator: bollinger, band: upper\|middle\|lower}` | `boll.upper\|middle\|lower` | 否 |
| `{indicator: atr, period: 14}` | `atr` | 否 |
| `{price: close}` | 直接取 bar | 否（上一根另取） |

**窗口与 NaN 语义**：
- 每 bar 只计算一次指标快照：窗口长度 = `max(所有 operand period) + 1`（+1 用于 prev），受 `indicator_window_max` 上限约束；输入取 `StrategyContext.recentBars`。
- `IndicatorCalculator` 输出 NaN → null；**任一 operand 为 null 或 NaN ⇒ 该比较/交叉为 false**（绝不触发），对齐 spec Edge 与 SC-001。
- **cross 的 prev 计算**：`ma`/`macd.hist` 复用内置 `prev`/`hist_prev`；`rsi`/`boll`/`atr`/`price`/`const` 参与 `cross` 时，需「shifted snapshot」——对去掉最后一根的窗口再算一次指标（每 bar 至多一次 shift，受 step budget 约束）。

**数值域（constitution II / FR-009 / SC-010）**：
- 指标/信号判断：float（bcmath 无法开方/指数，与 IndicatorCalculator 边界约定一致）。
- `qty_pct`（**按动作族界定**，结果均为 bcmath 字符串，随后由 `OrderExecutor` 经 `symbolFilters` + `alignToStep` 对齐）：
  - 开仓（open_long/open_short）：分数 = 分配资金的**名义额**比例；`refPrice` = 当前 bar `close`（on_bar）或最新 `ticker`（on_tick）；base qty = `bcdiv(bcmul(allocated_capital, qty_pct, 8), refPrice, 8)`；`refPrice ≤ 0` 或不可得 ⇒ 不产出信号（记 warning，绝不静默下单）。
  - 平仓（close_long/close_short）：分数 = 当前持仓 base 数量的比例（来自内存持仓快照）；qty = `bcmul(positionQty, qty_pct, 8)`；无持仓 ⇒ 不产出信号。
- 显式 `qty`：直接为 bcmath 字符串，交由 `OrderExecutor` 对齐。
- 崩溃恢复、状态、金额落库全部走既有 bcmath 路径。

## 6. 与内置策略语义等价口径（SC-002）

**Decision**：以两条 parity 测试对内建策略建立等价基准，口径统一为「相同 fixture、相同参数、相同指标实现下，两者**决策序列（触发 bar、side、qty）** 100% 一致」，排除撮合、滑点与手续费差异（spec Assumptions）。

| 基准 | 内建策略 | 等价 DSL 表达 | 断言 |
|---|---|---|---|
| a | `spot_dca` | 周期触发 = `{op: true}` + `trigger: on_bar`，`qty` = 定额 | 每 N 根 bar 触发一次买入，触发 bar/side/qty 一致 |
| b | `spot_grid` | 小网格（2–3 档）：`{price: close}` `gt`/`lt` `{const: level}` 复现 buy/sell | 穿越各档的买卖序列（触发 bar/side/qty）一致 |

**Rationale**：README 验收口径为「与 PHP 内置策略语义等价」（US3）；等价口径以信号为准（spec Assumptions），不引入撮合模拟，避免 fixture 依赖交易所行为。

**fixture 约定**：固定升序 `ExchangeKline` 序列 + 固定参数，双跑同一 `IndicatorCalculator`（SC-002 场景 2：逐点一致，含 NaN→null）。

## 7. 回测预览复用 P8 HistoricalQuery（FR-012 / SC-006）

**Decision**：`DslPreviewService` 分页调用 `HistoricalQuery`（`ArchiveDataType::KlineBar`，symbol+interval+UTC `[from,to)`），默认窗口 30 天、`pageSize` 取 config，逐 bar 回放**同一** `DslInterpreter` 与同一 `IndicatorCalculator`；输出信号序列 + 简易资金曲线（无滑点/手续费，显式标注假设）。

**Rationale**：SC-006「预览所见即实跑所得」要求预览与实跑共用同一解释器/指标路径，故不改写任何决策语义，仅替换数据源（历史行 → `ExchangeKline`）。

| 预览输出 | 字段 | 说明 |
|---|---|---|
| 信号序列 | `signals[{barTime, action, qty, ruleIndex}]` | 与实跑同路径产出 |
| 简易资金曲线 | `equityCurve[{barTime, equity}]` | 按信号撮合，不含滑点/手续费 |
| 统计 | `barCount` / `signalCount` / `triggerRate` | FR-012 |
| 假设声明 | `assumption` | “未模拟滑点/手续费” |

**边界**：单 DSL / 单标的 / 单周期；绩效分析（Sharpe/回撤/胜率/盈亏比）归 P10；预览走编辑器请求路径，**不进行情协程**。

## 8. 状态、崩溃恢复与 schemaVersion 演进（FR-010 / SC-005）

**Decision**：`DslStrategy` 继承 `AbstractStrategy`，`SCHEMA_VERSION` 由 1 升至 2；`getState()` 在既有 `position_snapshot` 内新增 `dsl` 扩展键；`restoreState()` 增加 v1→v2 迁移分支；恢复幂等守卫用**独立一次性标志**（constitution IV）。

| DSL 扩展状态（`position_snapshot.dsl`） | 类型 | 说明 |
|---|---|---|
| `compiled_hash` | string | `sha1(原始脚本)`，恢复时校验脚本未被改写 |
| `last_signal_bar` | int\|null | 上次产出信号的 bar openTime（去重/冷却基准） |
| `cooldown_remaining` | int | 冷却剩余 bar 数（`risk.cooldown_bars`） |
| `pending_signal` | object\|null | 待执行信号（与既有 pending-signal 范式一致） |
| `recovered` | bool | 恢复一次性标志（独立于业务字段） |

**约束**：
- 快照版本高于当前支持版本 → 拒绝启动（沿用 `AbstractStrategy` 既有行为，spec Edge：版本演进不得静默按新语义误解释）。
- `DslStrategy` 实现 `RecoverablePendingOrders`（`pendingClientOrderIds()` + `applyRecoveredOrder()`），复用 P5.5 pending 单恢复范式。
- getState/restoreState 沿用既有 Redis 每 bar + DB 每 60s 双写。

## 9. 售卖门控回归（FR-015 / FR-016）

**Decision**：`custom_dsl` 本期加入 `config/strategy.implemented` 与 `config/plans.php`；套餐归属取 **pro + enterprise**，free 排除；并以结构性断言保证「任一套餐 strategies ⊆ implemented」。

| 配置 | 现状 | 变更 |
|---|---|---|
| `config/strategy.php` `implemented` | `[spot_grid, spot_dca, futures_grid]` | 追加 `custom_dsl` |
| `config/plans.php` free `strategies` | `[spot_grid, spot_dca]` | 不变（不含 custom_dsl） |
| `config/plans.php` pro `strategies` | `[spot_grid, spot_dca, futures_grid]` | 追加 `custom_dsl` |
| `config/plans.php` enterprise `strategies` | 同上 | 追加 `custom_dsl` |

**Rationale**：决策基线 ③ 明确本期对客售卖；free 排除与 `custom_dsl` 的 `risk_profile=custom` 一致（自定义策略属进阶能力）。结构性断言（`PlanStrategyWhitelistIntegrityTest`）防超卖（SC-008）。

## 10. 测试策略

| 层 | 内容 | 分组 |
|---|---|---|
| Unit | DslYamlParser 子集解析 + 预扫描拒绝 + 定位诊断 | default |
| Unit | DslCompiler 结构校验（四段/键白名单/类型/枚举/范围） | default |
| Unit | DslInterpreter 比较/交叉/逻辑/恒真求值 + NaN→null + step budget | default |
| Unit | DslStrategy 全生命周期 + state(v2) 往返 + 崩溃恢复 | default |
| Unit | Schema 演进（v1→v2 迁移；高版本拒绝） | default |
| Integration | 红线语料库（parse + runtime 双处拒绝） | default |
| Integration | 等价性（SpotDca / SpotGrid parity fixture） | default |
| Integration | 预览一致性（预览信号序列 == 实跑信号序列，复用同一解释器） | default |
| Integration | 门控 + 白名单结构性断言 | default |
| Integration | 计费闸门/熔断语义（配额耗尽零调用、OPEN 下平仓放行） | redis |
| Smoke | `poc:verify-dsl` 离线全链路断言 | smoke |

> constitution VI：PHPUnit 12 用静态 `#[DataProvider]`；redis 相关用 `--group=redis`；持久化字段走完整 Domain 层公开方法；tearDown 精确清理共享 TTL 键。

## 11. 风险与未决项

| 项 | 风险 | 缓解 |
|---|---|---|
| 自研 YAML 子集与用户预期偏差 | 用户写了完整 YAML 却被拒 | 明确文档化子集 + 定位诊断 + 语料库测试；拒绝时给出精确行 |
| 沙箱逃逸 | 未知原语/动态求值 | 判别式 AST + 白名单 match + 解析/运行双处红线语料库（SC-003） |
| cross 的 prev 缺失 | 非 ma/macd 指标交叉误判 | 复用内置 prev；其余用 shifted snapshot（每 bar 至多一次） |
| 预览与实跑偏差 | 用户误判上线结果 | 同一解释器路径（SC-006）+ 显式假设声明 |
| 等价口径争议 | 「等价」边界不清 | 口径固定为 (触发 bar, side, qty)，排除撮合/滑点 |
| schemaVersion 误解释 | 旧脚本按新语义跑 | 高版本拒绝启动；v1→v2 迁移分支显式 |
| 求值成本拖垮行情协程 | 单脚本每 bar 过重 | step/time budget + 规模上限（FR-004 / SC-009） |
| 白名单超卖 | 套餐含未实现策略 | 结构性断言 strategies ⊆ implemented（SC-008） |
| 预览阻塞调用 | HistoricalQuery 分页 IO | 编辑器请求路径，非行情协程；受 pageSize 约束 |
