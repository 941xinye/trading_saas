# Feature Specification: 自定义 DSL 策略引擎（P7）

**Feature Branch**: `006-custom-dsl-strategy`

**Created**: 2026-10-20

**Status**: Plan Complete（2026-10-20，plan 阶段 5 文档齐备，待 implement）

**Input**: User description: "P7 自定义 DSL 策略引擎：YAML 声明式策略（entry/exit/filter/risk 四段）+ 沙箱解释器 + Filament 在线编辑器 + 回测预览；销技术债 B2（DslParser/DslStrategy 骨架全部抛异常）"

**Plan 产物**：[research.md](research.md) / [data-model.md](data-model.md) / [contracts.md](contracts.md) / [plan.md](plan.md) / [quickstart.md](quickstart.md)（6 工作流 WS1-WS6；tasks.md 由 /speckit-tasks 产出）

**决策基线（specify 阶段确认）**：①语法载体 = 仅 YAML 四段声明式（TOML 留待后续）；②回测预览纳入本期（复用 P8 HistoricalQuery，轻量）；③custom_dsl 本期加入套餐白名单并对客售卖。

## User Scenarios & Testing *(mandatory)*

### User Story 1 - YAML 策略定义 → 装载 → 实跑闭环 (Priority: P1)

交易者在 Filament 的策略编辑器里用 YAML 写一条自定义策略（入场条件、出场条件、过滤条件、风控参数四段），保存后创建实例并启动。系统在实例装载时解析并编译该策略；装载成功后，策略按既有调度被驱动——K 线收盘（onBar）时解释器求值四段条件，命中入场条件产出开仓信号，经既有幂等下单路径实际下单，成交回报更新本地状态并被策略出场条件消费。整个周期每笔订单可按幂等单号查回。

**Why this priority**：这是 P7 的本体，也是技术债 B2 的清偿目标——`DslParser`/`DslStrategy` 目前所有方法抛异常，没有它，编辑器、沙箱、预览、白名单都无对象可承载。

**Independent Test**：写一条最简 YAML（如"MA 上穿则开多、下穿则平多"），装载并跑通 onBar→信号→下单→成交回报，验证订单回报与状态持久化两件事。

**Acceptance Scenarios**：

1. **Given** 租户编写了一条合法 YAML 策略并创建实例，**When** 实例装载启动，**Then** 策略被解析编译成功并进入运行态，非法 DSL 则在装载期被拒并给出定位错误。
2. **Given** 实例处于运行中且收到一根满足入场条件的 bar，**When** 解释器求值，**Then** 产出的开仓信号经既有幂等路径下单，每笔单按幂等单号可查回且不重复。
3. **Given** 实例运行中进程被强制终止后重启，**When** 恢复逻辑执行，**Then** 策略状态（自定义变量/指标窗口尾部/信号进度）从持久层正确重放，无重复下单。

---

### User Story 2 - 沙箱执行与安全红线 (Priority: P1)

平台以"白名单原语 + 纯解释器"方式执行 DSL，绝不把用户脚本当作可执行代码：不调用 `eval`/`exec`，不触碰文件系统、网络、shell、类反射与动态包含。DSL 只能使用受支持的触发器、动作、指标与比较/逻辑/算术运算符；越界即拒。同时强制脚本规模与求值成本上限，防止一条脚本拖垮行情协程进程。

**Why this priority**：用户可控脚本在长驻协程进程内执行，是最高的安全与稳定性风险面；红线一旦破口，其后果（任意代码执行/数据泄露/进程崩溃）不可逆，故与本体同优先级。

**Independent Test**：以语料库投喂含 `eval`/`exec`/文件读写/网络调用/系统命令/未知原语的脚本，断言在解析与运行两处均被拒；并投喂超长/深嵌套脚本断言被规模上限拦截。

**Acceptance Scenarios**：

1. **Given** 一条包含非白名单原语（如 `eval`、文件操作、网络请求、shell 命令）的脚本，**When** 装载或运行，**Then** 被拒绝并记录原因，绝不被执行。
2. **Given** 一条超过脚本大小/嵌套深度/指标数量上限的脚本，**When** 装载，**Then** 被拒绝并给出超限说明。
3. **Given** 一条合法但求值成本极高（如每 bar 步骤数超上限）的脚本，**When** 运行，**Then** 求值被中断，不影响行情协程进程推进，且失败不静默。

---

### User Story 3 - 与内置策略语义等价 (Priority: P2)

平台需要证明 DSL 不是"另一套玩法"：给定相同的 bar 序列与相同的指标实现，一条等价表达的 DSL 策略与对应的内置策略必须产出相同的交易决策（相同触发 bar 上相同的开/平方向与数量）。这既是验收目标，也是防止"解释器与内置实现各算一套"导致用户困惑与风控失真的护栏。

**Why this priority**：README 明确验收口径为"与 PHP 内置策略语义等价"；它依赖 US1 先落地，但一旦缺失，DSL 的正确性无客观基准。

**Independent Test**：构造统一 fixture（固定 bar 序列 + 固定参数），分别驱动等价 DSL 策略与内置策略，断言两者决策序列（side/qty/触发 bar）完全一致。

**Acceptance Scenarios**：

1. **Given** 一组固定 bar 序列与等价参数，**When** 分别驱动等价 DSL 与内置策略，**Then** 两者的开/平决策在触发 bar、方向、数量上完全一致。
2. **Given** DSL 引用了某指标，**When** 与内置策略使用同一指标实现，**Then** 指标输出（含 NaN→null 语义）逐点一致。

---

### User Story 4 - 在线编辑器与回测预览 (Priority: P2)

交易者在 Filament 内编写 DSL 时得到即时反馈：结构/语法校验带错误定位、可保存草稿、可"试装载"编译校验而不真正启动实例；并可对单条策略在历史 K 线上跑一次轻量回测预览，看到信号序列与简易资金曲线，用于上线前的快速自检。预览与实跑共用同一解释器与指标实现，确保"预览所见即实跑所得"。

**Why this priority**：这是对客体验与安全前置的核心——把错误挡在保存/启动之前，把结果预期前置到上线之前；它依赖 US1 的解析器与 P8 的历史查询能力（均已具备）。

**Independent Test**：在编辑器中写一条含错误的 DSL 断言即时定位；写一条正确 DSL 对历史数据跑预览，断言输出信号序列与实跑同一策略的信号序列一致。

**Acceptance Scenarios**：

1. **Given** 编辑器内输入含错误的 DSL，**When** 触发校验，**Then** 返回带行/字段定位的可读错误，保存/试装载被阻断。
2. **Given** 一条合法 DSL 与一段历史区间，**When** 触发预览，**Then** 返回信号序列与简易资金曲线，且其信号序列与同策略实跑路径一致。
3. **Given** 租户 A 的 DSL 草稿，**When** 租户 B 查询，**Then** 彼此不可见（租户隔离）。

---

### User Story 5 - 售卖门控与计费/风控接线 (Priority: P2)

DSL 策略通过验证后回归套餐策略白名单：订阅含自定义策略套餐的租户可创建 DSL 实例，未订阅者被拒；平台结构性断言保证白名单不含任何未实现策略。DSL 下单与既有策略同等走两阶段计费闸门（动作前校验+冻结、受理结算、拒绝/异常冲正、拒绝路径交易所零调用），并同等受账户级熔断（只停开新仓、平仓放行）约束。

**Why this priority**：计费与风控边界是平台宪法级约束（V），不可因新增策略而绕行；白名单回归是交付完成标志。它复用既有闸门路径，工作量小于 US1。

**Independent Test**：以含/不含自定义策略的两个套餐租户分别尝试创建 DSL 实例断言门控；以配额耗尽租户提交 DSL 下单断言交易所零调用；熔断 OPEN 下断言平仓放行、开仓拦截。

**Acceptance Scenarios**：

1. **Given** 套餐白名单已含自定义策略，**When** 订阅该套餐的租户创建 DSL 实例，**Then** 创建成功；未订阅者创建被拒。
2. **Given** 平台任意套餐配置，**When** 检查策略白名单，**Then** 白名单中每个策略均已实现（结构性断言，防超卖）。
3. **Given** 租户当日下单配额已耗尽，**When** 提交 DSL 开仓，**Then** 在交易所调用前被拒，配额计数不变；配额充足受理时按既有语义结算，拒绝/异常冲正。

---

### Edge Cases

- 恶意/畸形 YAML：锚点别名爆炸、超深嵌套、超长字符串——解析器必须限深限长并拒绝，绝不因解析本身耗尽内存/CPU。
- 指标窗口不足（可用 bar 数 < 指标周期）：指标输出 null，对应条件不成立，不得误触发信号。
- 无效参数（周期 ≤ 0、数量/价格为 0 或负、除零）：必须在装载/校验期拒绝并定位，不得运行期静默产生脏信号。
- 数值越界（数量/价格/杠杆超出交易所规格或平台风控上限）：拒绝并说明原因，不得交由交易所侧报错掩盖。
- YAML 语法错误、未知原语、未知字段、类型不匹配：装载期定位报错（行/字段），绝不静默降级或忽略。
- DSL schemaVersion 演进：旧版本脚本在版本升级后的处理策略必须明确（拒绝并提示迁移，或按版本迁移），不得静默按新语义误解释。
- 崩溃恢复与指标尾部：恢复后指标窗口尾部与自定义变量必须与崩溃前一致，恢复幂等守卫用独立标志（constitution IV）。
- 时区语义：bar 时间为 UTC；用户若给出本地时间语义必须显式声明（本期不含时区偏移 DSL 语法）。
- 同一租户多实例共用/复制脚本：脚本的归属与修改传播语义必须明确（实例级快照 vs 租户级引用），避免运行中实例被静默改写。
- 中文/Unicode/注释：脚本中的中文注释与字符串必须被正确解析且不影响定位信息。
- 预览与实跑偏差：预览不模拟滑点/手续费（P10 范围），预览结果必须注明该假设，避免用户误判。

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: 系统 MUST 支持用 YAML 定义自定义策略，包含四段——entry（入场条件）、exit（出场条件）、filter（前置过滤/闸门条件）、risk（风控参数），以及元信息（名称、交易对、周期、方向、schemaVersion）。
- **FR-002**: 系统 MUST 提供 DSL 解析器（YAML → 结构化模型 → 可执行 AST）；解析/校验错误 MUST 携带定位（行/字段）与人类可读信息，MUST NOT 静默降级或忽略未知字段。
- **FR-003**: 解释器 MUST 仅在白名单原语内求值：触发器 `on_bar`/`on_tick`/`on_fill`；动作 `open_long`/`open_short`/`close_long`/`close_short`/`noop`；指标 `ma`/`rsi`/`macd`/`bollinger`/`atr`；以及比较/逻辑/交叉运算符（本期不含算术与任意表达式，见 Assumptions）。MUST NOT 执行 `eval`/`exec`、任意 PHP、文件系统、网络、shell、类反射或动态包含（沙箱红线，不可协商）。
- **FR-004**: 沙箱 MUST 强制规模与成本上限（脚本大小、条件嵌套深度、指标数量、每 bar 求值步骤数/耗时）；超限 MUST 拒绝或中断求值并记录，不影响行情协程进程推进。
- **FR-005**: DSL MUST 在写入/启动实例之前完成解析与编译；非法 DSL MUST 被拒（创建实例失败并说明原因），MUST NOT 进入运行态。
- **FR-006**: DSL 策略 MUST 复用 `StrategyInterface` 全生命周期（onTick/onBar/onFill/shouldOpen/shouldClose/getState/restoreState），由既有 StrategyRunner 一致驱动；下单 MUST 走既有 OrderExecutor 幂等 `clientOrderId` 模板。
- **FR-007**: DSL 策略 MUST 与内置策略语义等价：给定同一 bar 序列、同一参数、同一指标实现，等价表达的 DSL 与内置策略在触发 bar、开/平方向、数量上决策一致。
- **FR-008**: DSL 指标计算 MUST 复用既有 `IndicatorCalculator` 纯函数实现与 NaN→null 语义，MUST NOT 另起一套算法。
- **FR-009**: 全部金额/数量/价格计算 MUST 用 bcmath 字符串运算，时间统一 UTC，`clientOrderId` 模板不变（constitution II）。
- **FR-010**: DSL 策略状态 MUST 纳入既有崩溃恢复双写链（Redis 每 bar + DB 快照），恢复幂等守卫用独立一次性标志（constitution IV）。
- **FR-011**: Filament MUST 提供在线编辑器：即时结构/语法校验与错误定位、保存草稿、试装载（仅编译校验不启动）、实例关联；编辑器资源 MUST 租户隔离。
- **FR-012**: 回测预览 MUST 复用 P8 `HistoricalQuery` 在历史 K 线上回放单条 DSL，输出信号序列与简易资金曲线（含信号数/触发率）；预览 MUST 与实跑使用同一解释器与指标实现。
- **FR-013**: 账户级熔断对 DSL 策略 MUST 保持「只停开新仓」语义：DSL 开仓/加仓拦截，平仓/减仓永远放行（constitution V）。
- **FR-014**: DSL 下单 MUST 走既有两阶段计量闸门：动作前权益校验+冻结、受理结算、拒绝/异常冲正，拒绝路径交易所零调用（constitution V）。
- **FR-015**: `custom_dsl` MUST 在验证通过后回归套餐策略白名单，且平台 MUST 提供结构性断言保证任一套餐白名单不含未实现策略。
- **FR-016**: DSL 脚本与实例 MUST 受租户隔离约束（TenantScope），跨租户不可见、不可相互引用。
- **FR-017**: DSL MUST NOT 引入第三方表达式/模板引擎或任何动态求值扩展作为求值路径（除非 plan 阶段论证其不破坏 FR-003 红线）；求值路径 MUST 为可审计的纯 PHP 白名单解释。

### Key Entities *(include if feature involves data)*

- **自定义策略定义（Custom Strategy Definition）**：YAML 文本 + 解析后的结构化模型（元信息 + entry/exit/filter/risk 四段 + 指标引用集合），带 schemaVersion；为编辑器与装载的输入单元。
- **DSL 编译产物（Compiled AST）**：白名单节点树，仅供解释器求值；不可变、可校验、带求值成本估算。
- **DSL 策略实例（Custom DSL Instance）**：挂在既有 strategy_instances 上，携带脚本快照、参数、运行状态与持久化状态。
- **校验诊断（Validation Diagnostics）**：错误/警告的位置（行/字段）、级别、消息；驱动编辑器反馈与装载拒绝。
- **预览结果（Preview Result）**：信号序列 + 简易资金曲线 + 统计（信号数/触发率）；注明"未模拟滑点/手续费"假设。
- **白名单门控（Entitlement Gate）**：套餐 → 策略白名单交集；`config/strategy.implemented` 与 `config/plans.php` 取交集为硬闸门。

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 一条 YAML DSL 装载后跑通 onBar→信号→下单→成交回报闭环（离线等价 fixture 或 testnet 实跑），每笔订单按幂等单号可查回一致。
- **SC-002**: 语义等价验证通过：同一 fixture 下等价 DSL 与内置策略的决策序列（触发 bar/方向/数量）100% 一致（自动化断言）。
- **SC-003**: 沙箱红线验证：非白名单原语（eval/exec/文件/网络/shell/未知原语）在解析与运行两处 100% 被拒（语料库自动化断言）。
- **SC-004**: 非法 DSL 拒绝：语法/字段/类型/越界/超限用例 100% 在装载期被拒并给出定位。
- **SC-005**: 幂等与恢复验证：SIGKILL 强杀后重启，DSL 状态与指标窗口尾部正确重放、无重复下单（对齐 P1 崩溃恢复验证法）。
- **SC-006**: 预览一致性验证：同一 DSL 在历史预览与实跑路径（同一解释器）产出的信号序列一致。
- **SC-007**: 计费/风控验证：配额耗尽路径交易所零调用；熔断 OPEN 下 DSL 平仓 100% 放行、开仓 100% 拦截（自动化断言）。
- **SC-008**: 门控回归验证：白名单结构性断言通过（任一套餐白名单不含未实现策略）；含 DSL 套餐租户可创建、不含的被拒。
- **SC-009**: 性能上界验证：单条 DSL 每 bar 解释求值开销有可测上界（fixture 断言，量级与 bar 频率不冲突），且远低于行情协程调度预算。
- **SC-010**: 精度回归：边界用例（极小数量、极大数量、尾数舍入）下金额/数量/价格计算无浮点精度损失。

## Assumptions

- 语法载体：本期唯一为 YAML（README 的「YAML/TOML」收窄为 YAML）；TOML 支持明确不在本期范围。
- 指标集限于 `config/strategy.php` `dsl_schema` 的 `ma`/`rsi`/`macd`/`bollinger`/`atr`；用户自定义指标/任意表达式不在本期。
- 触发器限于 `on_bar`/`on_tick`/`on_fill`，动作限于 `open_long`/`open_short`/`close_long`/`close_short`/`noop`；多标的联动、跨实例协同不在本期。
- 首期仅现货（`custom_dsl` 的 `category` 为 `spot`）；合约 DSL 不在本期。
- 回测预览为轻量实现（复用 P8 `HistoricalQuery`，单条 DSL/单标的/单周期，不模拟滑点与手续费）；完整绩效分析（Sharpe/最大回撤/胜率/盈亏比）归 P10。
- 语义等价的比对基准为既有已落地策略（`spot_grid`/`spot_dca`/`futures_grid`）在同一 fixture 下的决策；等价口径以信号（side/qty/触发 bar）为准，成交撮合与滑点差异除外。
- 沙箱为纯 PHP 白名单解释器，不引入第三方表达式引擎作为求值路径（见 FR-017）；若 plan 论证引入，必须证明不破坏 FR-003 红线。
- DSL 实例的脚本归属采用实例级快照语义（实例持有保存时脚本副本），避免运行中实例被外部改写；租户级共享/引用不在本期。
- `custom_dsl` 本期加入 `config/strategy.implemented` 与 `plans.php` 白名单并对客售卖（决策基线 ③）。
- testnet/主网可达性依网络环境而定（坑 #23）；验证按宪法准备离线断言 + 真实链路两条路径。
- 依赖既有能力均已具备：`StrategyInterface` 契约、`StrategyRunner` 调度、`OrderExecutor` 幂等下单、`IndicatorCalculator` 指标、`QuotaGuard`/两阶段计量闸门、`CircuitBreaker`/`RiskGuard`、P8 `HistoricalQuery`。
