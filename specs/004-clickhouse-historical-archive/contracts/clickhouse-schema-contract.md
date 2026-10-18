# Contract: ClickHouse Schema（DDL 唯一事实源）

**Feature**: 004-clickhouse-historical-archive | **Date**: 2026-10-18
**执行方式**: `php artisan archive:schema` 幂等执行（全部 `CREATE TABLE IF NOT EXISTS`）；research R-003/R-004 是类型决策依据。所有表 ENGINE=MergeTree，附加审计列 `batch_id`/`archived_at`，时间统一 UTC。

---

## 通用约定

- **Decimal 精度逐列镜像 MySQL**；写入 JSONEachRow 传字符串，读取带 `output_format_json_quote_decimals=1` 返回字符串（constitution II 精度红线）。
- **MySQL 可空列 → CH `Nullable(...)`**（保真映射，null ↔ JSON null；行哈希规范化时 null → `''`）。非空列一律不用 Nullable（ORDER BY 列全部非空）。
- **读取统一设置**（所有 SELECT）：`output_format_json_quote_decimals=1`、`output_format_json_quote_64bit_integers=1`（UInt64 id/batch_id 回读为字符串，规避 PHP 大整数精度）。
- **写入格式**：`INSERT INTO {db}.{table} FORMAT JSONEachRow`，body 为 NDJSON；Decimal/timestamp 传字符串，nullable 缺省列传 JSON `null` 或省略。
- **轻量删除**：`DELETE FROM {db}.{table} WHERE batch_id = {id}`（批次重跑清理；对后续 SELECT 立即可见）。

---

## ch_kline_bars（public 数据）

```sql
CREATE TABLE IF NOT EXISTS ch_kline_bars
(
    `id`            UInt64,
    `exchange_code` LowCardinality(String),
    `symbol`        LowCardinality(String),
    `interval`      LowCardinality(String),
    `open_time`     DateTime('UTC'),
    `close_time`    DateTime('UTC'),
    `open`          Decimal(20, 8),
    `high`          Decimal(20, 8),
    `low`           Decimal(20, 8),
    `close`         Decimal(20, 8),
    `volume`        Decimal(20, 8),
    `quote_volume`  Decimal(20, 8),
    `trades_count`  UInt32,
    `created_at`    DateTime('UTC'),
    `updated_at`    DateTime('UTC'),
    `batch_id`      UInt64,
    `archived_at`   DateTime('UTC')
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(open_time)
ORDER BY (exchange_code, symbol, interval, open_time, id);
```

## ch_signals（tenant 数据）

```sql
CREATE TABLE IF NOT EXISTS ch_signals
(
    `id`              UInt64,
    `tenant_id`       UInt64,
    `subscription_id` UInt64,
    `symbol`          LowCardinality(String),
    `interval`        LowCardinality(String),
    `direction`       LowCardinality(String),
    `entry_price`     Decimal(20, 8),
    `exit_price`      Decimal(20, 8),
    `stop_loss`       Nullable(Decimal(20, 8)),
    `confidence`      Decimal(5, 2),
    `source`          LowCardinality(String),
    `rationale`       String,
    `raw_payload`     String,
    `triggered_at`    DateTime('UTC'),
    `created_at`      DateTime('UTC'),
    `updated_at`      DateTime('UTC'),
    `batch_id`        UInt64,
    `archived_at`     DateTime('UTC')
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(triggered_at)
ORDER BY (tenant_id, symbol, triggered_at, id);
```

## ch_strategy_orders（tenant 数据）

```sql
CREATE TABLE IF NOT EXISTS ch_strategy_orders
(
    `id`                   UInt64,
    `tenant_id`            UInt64,
    `strategy_instance_id` UInt64,
    `exchange_order_id`    Nullable(String),
    `client_order_id`      String,
    `symbol`               LowCardinality(String),
    `side`                 LowCardinality(String),
    `type`                 LowCardinality(String),
    `quantity`             Decimal(20, 8),
    `price`                Nullable(Decimal(20, 8)),
    `stop_price`           Nullable(Decimal(20, 8)),
    `leverage`             Nullable(UInt16),
    `reduce_only`          UInt8,
    `status`               LowCardinality(String),
    `filled_qty`           Decimal(20, 8),
    `avg_fill_price`       Nullable(Decimal(20, 8)),
    `fee`                  Decimal(20, 8),
    `error_message`        String,
    `placed_at`            Nullable(DateTime('UTC')),
    `filled_at`            Nullable(DateTime('UTC')),
    `created_at`           DateTime('UTC'),
    `updated_at`           DateTime('UTC'),
    `batch_id`             UInt64,
    `archived_at`          DateTime('UTC')
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(created_at)
ORDER BY (tenant_id, created_at, id);
```

## ch_liquidation_events（public 数据）

```sql
CREATE TABLE IF NOT EXISTS ch_liquidation_events
(
    `id`                UInt64,
    `exchange_code`     LowCardinality(String),
    `symbol`            LowCardinality(String),
    `side`              LowCardinality(String),
    `price`             Decimal(20, 8),
    `qty`               Decimal(20, 8),
    `value_usd`         Decimal(20, 2),
    `event_ts`          DateTime('UTC'),
    `exchange_event_id` String,
    `created_at`        DateTime('UTC'),
    `updated_at`        DateTime('UTC'),
    `batch_id`          UInt64,
    `archived_at`       DateTime('UTC')
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(event_ts)
ORDER BY (symbol, event_ts, id);
```

---

## MySQL 侧 null → CH 侧值规范化（搬迁映射）

| MySQL 值 | CH 写入值 | 回读值 | 规范化串（哈希/比对） |
|---|---|---|---|
| NULL（可空列） | JSON `null` | JSON `null` → PHP `null` | `''` |
| decimal 字符串 | 原字符串 | 带引号字符串 | 原字符串（逐位） |
| timestamp | `'Y-m-d H:i:s'`（UTC） | `'Y-m-d H:i:s'` | 同 |
| json 列 | 原 JSON 串 | 字符串 | 原 JSON 串（不重排序） |

**口径**：`rationale`/`raw_payload`/`error_message`/`exchange_event_id` 的 NULL → `''`（String 非 Nullable，与 data-model 空串语义归一一致）；其余可空数值/时间列用 Nullable 保真。

**验收锚点**：round-trip 断言 = MySQL 行规范化串 == CH 回读行规范化串，逐位相等（集成测试固定用例）。
