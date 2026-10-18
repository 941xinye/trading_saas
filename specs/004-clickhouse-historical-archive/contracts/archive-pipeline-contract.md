# Contract: Archive Pipeline（归档命令族与搬迁管线契约）

**Feature**: 004-clickhouse-historical-archive | **Date**: 2026-10-18
**触发方式**（clarify 决定 1 / FR-013）：全部手动命令触发，无调度器绑定（定时自动化留后续增强）。
**落点**: `app/Console/Commands/Archive{Schema,Run,Verify,Status}Command.php` + `app/Domain/Archive/Services/ArchivePipeline.php`。

---

## 1. 命令签名

```text
php artisan archive:schema
    幂等建立 4 张 CH 表（CREATE TABLE IF NOT EXISTS，DDL = contracts/clickhouse-schema-contract.md）。
    输出：逐表创建/已存在确认。CH 不可达 → 非零退出并报原因。

php artisan archive:run {type?} {--apply} {--batch-window-hours=} {--chunk-size=} {--throttle-ms=} {--max-batches=} {--from=} {--to=}
    执行归档管线（copy → verify → [apply]），默认 dry-run（clarify 决定 3 / FR-005）。
    {type}                省略 = 四类型顺序执行（enabled 类型）；未启用类型显式报错退出
    {--apply}             显式二次确认：对 verify_status=passed 且未 applied 的批次执行主库删除
    {--batch-window-hours=} 批次时间片窗口（默认取 config）
    {--chunk-size=}       主库分块读取行数（默认取 config）
    {--throttle-ms=}      块间限速毫秒（默认取 config）
    {--max-batches=}      本次最多处理的批次数（首跑存量限速，FR-012）
    {--from= / --to=}     限定切片区间（UTC，Y-m-d 或 Y-m-d H:i:s）；省略 = 从 copied_watermark 到 now-retention

php artisan archive:verify {type?} {--batch=}
    独立复检（US5：归档前后各跑一次校验）：对指定批次（省略 = 该类型全部 copied/passed 未 applied 批次）
    重跑三级校验，输出批次清单（时间范围/行数/校验状态/明细）；复检 failed 的批次立即降级
    （verify_status=failed + failed_reason），使其不可进入 apply 删除候选。

php artisan archive:status {type?}
    归档状态查询（FR-009）：每类型一行——retention_days / enabled / copied_watermark /
    applied_watermark / 待归档量估算 / 最近 N 批次（id、时间范围、行数、verify_status、applied_at）。
```

**退出码**：`0` 成功；`1` 运行期失败（CH 不可用、校验不过、行数账目失配等，failed_reason 已记账）；`2` 用法/参数错误。

## 2. 管线阶段契约（单批次）

| 阶段 | 动作 | 账目写入 | 失败行为 |
|---|---|---|---|
| 建批 | 按切片谓词定 `[from,to)`，查 `uniq_batch_type_range` 防重复建批；续跑时复用未完成批次行 | 新行 `pending` | — |
| copy | 先 `DELETE FROM ch WHERE batch_id` 清历史半途行（幂等守卫，R-003）→ 主库按 id 升序 chunk 流式读（谓词=时间切片）→ 逐块 JSONEachRow 写 CH → 块间 throttle | `source_rows`（主库实读）、`archived_rows`（CH 实写）、状态 `copied` | 状态 `failed` + failed_reason；主库零改动；重跑自动清半途行 |
| verify | 三级校验（R-006）：行数 / 行哈希和（可交换）/ 抽样全字段比对 | `verify_status = passed|failed` | `failed` 批次永不进入删除候选（FR-005） |
| apply（仅 `--apply`） | `DB::transaction { 主库切片行数再核对 == archived_rows（不符即 abort）→ 分块限速删除主库切片行 → 推进 applied_watermark → 批次标 applied_at }` | 删除与水位**同一事务**（R-010，路由正确性根基） | abort 即整体回滚，无半删状态 |

**dry-run 语义**（默认）：完整执行 copy+verify（真实写 CH、真实校验、真实记账），**不执行 apply 阶段**；报告输出「可删范围」（passed 批次的切片与行数）供人工确认后追加 `--apply` 复跑。

## 3. 安全红线（违反即 abort，非零退出）

1. **verify gate**：`verify_status != 'passed'` 或 `source_rows != archived_rows` 的批次，任何模式下不得进入主库删除（FR-005）。
2. **apply 行数再核对**：删除事务内主库切片行数必须仍等于 `archived_rows`（防 verify 后有意外写入）。
3. **安全上界**：切片上界恒 ≤ `now(UTC) - retention_days`（kline_bars 另加 close_time 闭合保险，R-005）；`--from/--to` 越界即截断并警告。
4. **限速**：chunk/throttle 参数有最小值下限（config），防误配打满主库 IO（FR-012）。
5. **幂等**：同批次重跑行数不变（先删后插）；中断续跑从账目状态恢复（FR-004 / constitution IV）。

## 4. 输出报告（单批次行，人工可读）

```text
[batch 42] kline_bar 2026-05-01 00:00:00 ~ 2026-05-02 00:00:00
  source_rows=86400 archived_rows=86400
  verify: rows=OK hash_sum=OK sample=5/5=OK  → PASSED
  action: copied (dry-run) | deleted (apply) | blocked: <reason>
汇总：N 批处理 / M passed / K failed / 可删范围 [x, y) 共 Z 行（--apply 未加，未删除）
```

## 5. 依赖边界（FR-008 / US4，机制性保证）

- P1-P5 代码零 import 指向 `App\Domain\Archive`（analyze 阶段 gate 核对 import 闭包）；归档管线是纯消费侧旁路命令。
- CH 故障 → 当前批次 `failed` + 报错退出（退出码 1）；主库账目一致（copy 中断的半途 CH 行由 batch_id 在续跑时清理，主库零污染）。
- 命令进程为独立 artisan 进程（无协程）；与 market:feed / queue:work 生命周期无关（constitution III 边界）。
