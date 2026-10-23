# 变更摘要：AI 行情分析探针产品化

> **日期**: 2026-10-23
> **前置**: spec 010 合约原生账实对账（commit `08e152e`）
> **后置**: 下一个 feature 待定
> **两次 commit**: `10297db`（fix）+ `a28f42f`（feat）

---

## 背景

P1-P5.5 核心功能闭环、spec 001-010 全部交付后，对 AI 行情分析子系统做了一次端到端检查。检查中发现两个问题：

1. **exit_price NOT NULL 防御性缺失**：`AbstractAIPrompter::toSignal()` 方向一致性校验分支中，矛盾方向的 `exit` 被置 `null`，而 `signals.exit_price` 列是 `NOT NULL`。正常 AI 输出不会矛盾，但属于"万一幻觉就炸"的边界风险。
2. **手动探针脚本无法复用**：为验证 DeepSeek 连通性和多周期 AI 研判效果，曾写过一次性脚本。每次调试都要重写，且无法在运维/部署时直接调用。用户明确要求产品化为 artisan 命令。

此外，探针过程中发现 Binance `/api/v3/klines` 返回的最后一根 bar 是**当前正在进行的桶**，close/high/low/volume 会继续变动，指标漂移。需要在命令输出和 AI prompt 中都体现这一时效性信息。

---

## 一、exit_price NOT NULL 防御性修复（commit `10297db`）

### 改动清单

| 文件 | 动作 |
|---|---|
| `app/Domain/Signal/Prompters/AbstractAIPrompter.php` | 修改 |
| `database/migrations/2026_10_23_080241_make_exit_price_nullable_on_signals_table.php` | 新增（已应用） |
| `tests/Integration/Signal/DeepSeekAdapterTest.php` | +2 条测试 |

### 代码变更

**修复前**
```php
if ($direction === 'long') {
    if ($exit !== null && (float) $exit <= $entryFloat) {
        $exit = null;  // ← NOT NULL 列风险
    }
}
```

**修复后**
```php
if ($direction === 'long') {
    if ($exit !== null && (float) $exit <= $entryFloat) {
        $exit = $this->formatPrice($entryFloat * 1.0075);  // ← 0.75% 百分比兜底
    }
}
// short → $entry * 0.9925
// neutral → 0.75% 百分比占位（方向无意义但 NOT NULL 列必须有值）
// stop 矛盾方向仍置 null（signals.stop_loss 列已 nullable）
```

### DB 层双保险

`exit_price` 列改为 `nullable()`，与已 nullable 的 `stop_loss` 对齐。Migration 已在本地应用。

### 风险评估

改动前：RuleEngineFallback 和正常 AI 输出方向都不会矛盾，风险极低但非零。
改动后：代码层通过百分比兜底消除了 null 产出源，DB 层 nullable 作为最后一道防线。

---

## 二、ai:multitf-analyze 命令（commit `a28f42f`）

### 改动清单

| 文件 | 动作 | 行数 |
|---|---|---|
| `app/Console/Commands/MultiTfAnalyzeCommand.php` | 新增 | 473 |
| `tests/Feature/Signal/MultiTfAnalyzeCommandTest.php` | 新增 | 207 |

### 命令签名

```bash
php artisan ai:multitf-analyze BTCUSDT                    # 默认 5m/15m/1h/4h
php artisan ai:multitf-analyze BTCUSDT --tf=5m,1h         # 指定周期
php artisan ai:multitf-analyze ETHUSDT --closed-only      # 只用已闭合 bar
php artisan ai:multitf-analyze BTCUSDT --json             # 原始 JSON 输出
php artisan ai:multitf-analyze BTCUSDT --provider=qwen    # 指定 AI Provider
php artisan ai:multitf-analyze BTCUSDT --exchange=okx --market=futures
```

### 架构

```
MultiTfAnalyzeCommand
  → ExchangeManager::adapter($exchange, $market)
    → BinanceSpotAdapter::getKline()
      → REST POST /api/v3/klines
  → IndicatorCalculator::compute()     （纯函数，无 IO）
  → 时效性检测（openTime 对齐当前桶起点）
  → Http::post(/chat/completions)     （绕过 AbstractAIPrompter）
  → printReport() 终端格式化输出
```

### 关键设计决策

| 点 | 说明 |
|---|---|
| **数据源直连 REST** | 无 DB / Redis 缓存，每次调用都是准实时快照 |
| **绕过 AbstractAIPrompter** | 命令行一次性分析，不走 tenant/quota 计量链路。抽象层绑定了生产多租户语义，命令行不需要 |
| **只读不写** | 不写 DB、不落队列、不碰 Redis，纯 read-only 探测 |
| **Provider 可切换** | 从 `config/ai.php` 读取，默认 `deepseek`，支持 `--provider=openai\|qwen\|ollama` |

### 时效性处理（三层）

**1) 行级标注**

```
拉取 5m... ✓ close=81799.65 RSI=50.7 MACD=4.542 ATR=76.11 | 🔴 进行中 (93% 已过)
拉取 4h... ✓ close=81799.65 RSI=45.3 MACD=-186.5 ATR=5851 | 🔴 进行中 (29% 已过)
```

**2) 汇总警告**

```
以下周期最后一根 bar 正在进行中，指标还会变动：5m(93%)、4h(29%)
  用 --closed-only 丢弃进行中 bar 可让指标基于确定收盘价（但数据时点滞后一周期）
```

**3) Prompt 注入 + `--closed-only` 选项**

System Prompt 第 6 条新增时效性规则：
> 若 ≥1 个高权重周期（1h/4h）处于进行中，应降低 conviction（-5~-15）并在 invalidates_when 中明确"等待该周期闭合确认方向"。

运行时验证 AI 正确消费了这条规则：
> "4h 进行中且 MACD hist=-186.5 空头，... 当前 4h 进行中需等待闭合确认"
> → conviction 自动降到 42（低于 50 阈值 → WAIT）

`--closed-only` 选项丢弃最后一根进行中 bar，指标基于已闭合收盘价。合成测试验证了丢弃逻辑命中。

### 生产 SignalPipeline 不受影响

生产链路通过 `SignalFeedRunner → BarAggregator` **只在桶闭合后**才触发 `AiAnalyzeJob`，拉的最后一根 bar 是刚开启的新桶（对指标贡献 ≈ 0），指标稳定性远好于命令行一次性探针。时效性处理仅对命令行探针有意义。

---

## 三、测试覆盖

| 测试文件 | 改动 | 覆盖点 |
|---|---|---|
| MultiTfAnalyzeCommandTest | **+3 条** | 默认模式 exit code 0 + HTTP 调用、`--json` 模式、`--closed-only` 丢弃进行中 bar + prompt 断言不含"进行中" |
| DeepSeekAdapterTest | **+2 条** | long 方向矛盾价位 → exit=entry*1.0075；short 方向矛盾价位 → exit=entry*0.9925 |

### 最终结果

```
MultiTfAnalyzeCommandTest          3 passed
DeepSeekAdapterTest                6 passed
IndicatorCalculatorTest            6 passed
BarAggregatorTest                  3 passed
SignalPipelineAiTest               3 passed
PriceDeviationGuardTest            1 passed
SignalDispatcherDedupTest          1 passed
SignalAutoFollowQueueDegradationTest 1 passed
SignalAutoFollowTest              20 passed
────────────────────────────────────────
Signal 全量                     42 passed / 0 failed / 152 assertions
```

### 真实运行验证

| 项 | 结果 |
|---|---|
| DeepSeek 真实连通 | ✅ 1.88-2.81s 响应，`deepseek-chat` → 返回 `deepseek-flash` 变体 |
| 四周期同时拉取 | ✅ 指标完整，4h MA99=null（Binance 只返回 97 根 4h bar < 99 窗口，数据源限制） |
| `--closed-only` 丢弃 | ✅ 全部 🟢 已闭合 |
| `--json` 输出 | ✅ 可被 jq / 管道脚本消费 |
| Prompt 时效性传递 | ✅ AI 摘要/共振/失效条件均引用"进行中"表述，conviction 自动降低 |

---

## 四、Git 提交

```
10297db  fix(signal): exit_price NOT NULL 防御性修复
a28f42f  feat(ai): 新增 ai:multitf-analyze 多周期 AI 行情研判命令
```

**未纳入本次变更的工作区文件**（无关改动）：
- `README.md`
- `PocFuturesLiveTestnetCommand.php`
- `deploy/acceptance.sh`
- `docs/overseas-node-runbook.md`
- `deploy/bootstrap-node.sh`、`docs/deploy-*.md`
- `dump.rdb`（Redis dump）

---

## 五、后续可优化项（低优先级）

| # | 项 | 说明 |
|---|---|---|
| 1 | 4h MA99 数据稀疏 | Binance 对长周期只返回有限历史（97 根 4h bar < 99 窗口），MA99 显示 `—`。可在命令注释中标注数据源限制，或增加重试/多端点 fallback |
| 2 | 订阅变更动态热更 | 当前命令需要显式传 `--provider` / `--exchange`。生产环境可增加动态读取用户订阅配置的路径 |
| 3 | 定时批量分析 + 通知 | 命令已具备只读幂等性，可通过 scheduler 定时跑，结果写入 Redis + 广播给订阅者。这将让 ai:multitf-analyze 从"手动工具"变成"生产辅助信号源" |
| 4 | 多交易所并行 | 当前每次只调一个交易所，可扩展为 `--all-exchanges` 并行拉取（swoole 协程，当前命令不走 swoole） |
