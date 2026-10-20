# Specification Quality Checklist: 自定义 DSL 策略引擎（P7）

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-10-20
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic (no implementation details)
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Notes

- **决策基线已确认（specify 阶段）**：①DSL 载体 = 仅 YAML 四段声明式（TOML 明确不在本期）；②回测预览纳入本期（轻量，复用 P8 历史查询）；③`custom_dsl` 本期加入套餐白名单并对客售卖。原三条待消歧项已由用户答复闭合，`[NEEDS CLARIFICATION]` 为 0。
- **技术名词说明（保留项）**：spec 中对 `clientOrderId`、bcmath/UTC、`StrategyInterface`、`IndicatorCalculator`、`HistoricalQuery` 的引用，是本项目宪法（II/IV/V）与既有 spec（005 同款句式）强制要求的**受约束领域词汇**（约束边界而非实现方案），HOW 细节留待 plan 阶段；故 "No implementation details" 与 "technology-agnostic" 判定为通过并在此备注。
- **验证方法说明**：SC 中的 testnet 实跑 / SIGKILL 崩溃恢复 / 交易所零调用断言，是项目宪法（III/IV/V）规定的标准验证路径（与 005 spec SC 同款），非实现细节。
- **范围红线**：沙箱禁 `eval`/`exec`/文件系统/网络/shell（FR-003 不可协商）；TOML、合约 DSL、自定义指标、多标的联动、完整绩效分析均明确排除本期。
