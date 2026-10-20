# Data Model: 合约网格策略 + 仓位级风控

> spec 005 plan 阶段数据模型设计。沿用现有 `strategy_instances` / `strategy_orders` / `circuit_breaker_events` 表结构，仅扩展必要字段；新增 `position_risk_events` 表用于仓位风控审计。

## 1. strategy_instances 表（扩展）

现有表已有 `leverage` 字段（nullable int），无需新增列。合约实例通过 `strategy.code = 'futures_grid'` 区分。

### 1.1 position_snapshot JSON Schema（合约网格）

`strategy_instances.position_snapshot` 为 JSON 字段，合约网格写入以下结构（与现货网格的 `grid_step/last_index/grids` 正交，按策略 code 区分）：

```json
{
  "schema_version": 2,
  "grid_step": "120.50000000",
  "last_index": 5,
  "position": {
    "side": "long",
    "size": "0.01000000",
    "entry_price": "61000.00000000",
    "leverage": 10,
    "liquidation_price": "55200.00000000",
    "maint_margin_rate": "0.0040",
    "margin": "61.00000000",
    "unrealized_pnl": "12.50000000"
  },
  "pending_signal": {
    "cell": 4,
    "side": "buy",
    "qty": "0.00500000",
    "reduce_only": false
  }
}
```

| 字段 | 类型 | 说明 |
|---|---|---|
| `schema_version` | int | 快照版本，合约网格用 2（现货为 1）；`AbstractStrategy::restoreState` 按版本迁移 |
| `grid_step` | string | 每格价格步长（bcmath 字符串） |
| `last_index` | int | 当前价格所在格索引（-1=下沿外，grid_count=上沿外，-2=未初始化） |
| `position.side` | string | `long` / `short` / `flat`（无持仓） |
| `position.size` | string | 持仓数量（base） |
| `position.entry_price` | string | 加权平均开仓价 |
| `position.leverage` | int | 杠杆倍数 |
| `position.liquidation_price` | string | 预估强平价（每次 getPosition 同步交易所值） |
| `position.maint_margin_rate` | string | 维持保证金率（从 leverageBracket 获取） |
| `position.margin` | string | 占用保证金 |
| `position.unrealized_pnl` | string | 未实现盈亏（quote） |
| `pending_signal` | object\|null | onBar 产生的待执行信号（与现货 pendingSignal 语义一致） |

### 1.2 status 枚举扩展

现有 `strategy_instances.status` 枚举：`stopped / starting / running / paused_by_breaker / error`。

**新增** `paused_by_position_risk`（仓位紧急风险暂停），与 `paused_by_breaker` 正交：
- `paused_by_breaker`：账户级熔断，停开新仓
- `paused_by_position_risk`：仓位风控触发全平后，实例暂停等待人工确认

```sql
ALTER TABLE strategy_instances
MODIFY COLUMN status ENUM(
  'stopped', 'starting', 'running',
  'paused_by_breaker', 'paused_by_reconcile',
  'paused_by_position_risk', 'error'
) NOT NULL DEFAULT 'stopped';
```

> 注：`paused_by_reconcile` 已在 P5.5 步骤 1 migration 中加入，本期再追加 `paused_by_position_risk`。

## 2. strategy_orders 表（扩展）

现有表结构满足合约需求，但需确认两个字段：

| 字段 | 现状 | 合约需求 |
|---|---|---|
| `type` | enum('market') | 扩展为 `market / limit`（合约可能用限价平仓，首期仅 market，预留） |
| `leverage` | 无 | 新增 `leverage INT NULL`（落库时记录该单杠杆，便于审计与恢复） |
| `reduce_only` | 无 | 新增 `reduce_only TINYINT(1) DEFAULT 0`（标记减仓/平仓单） |

```sql
ALTER TABLE strategy_orders
MODIFY COLUMN type ENUM('market', 'limit') NOT NULL DEFAULT 'market',
ADD COLUMN leverage INT NULL AFTER type,
ADD COLUMN reduce_only TINYINT(1) NOT NULL DEFAULT 0 AFTER leverage;
```

## 3. position_risk_events 表（新增）

仓位级风控动作审计表，对齐 `circuit_breaker_events` 风格。

```sql
CREATE TABLE position_risk_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  strategy_instance_id BIGINT UNSIGNED NOT NULL,
  symbol VARCHAR(20) NOT NULL,
  position_side ENUM('long', 'short') NOT NULL,
  action_type ENUM('warning', 'reduce', 'emergency_close') NOT NULL,
  trigger_reason VARCHAR(40) NOT NULL,
  margin_ratio DECIMAL(10, 6) NOT NULL COMMENT '触发时保证金率',
  mark_price DECIMAL(20, 8) NOT NULL,
  liquidation_price DECIMAL(20, 8) NULL,
  position_before JSON NOT NULL COMMENT '动作前仓位快照 {size,entry_price,unrealized_pnl}',
  position_after JSON NULL COMMENT '动作后仓位快照（减仓后；全平后为 null）',
  order_id VARCHAR(64) NULL COMMENT '关联 strategy_orders.client_order_id',
  status ENUM('pending', 'executed', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
  error_message VARCHAR(500) NULL,
  occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,

  INDEX idx_pre_tenant_instance (tenant_id, strategy_instance_id, occurred_at),
  INDEX idx_pre_tenant_symbol (tenant_id, symbol, occurred_at),
  CONSTRAINT fk_pre_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_pre_instance FOREIGN KEY (strategy_instance_id) REFERENCES strategy_instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

| 字段 | 说明 |
|---|---|
| `action_type` | `warning`=仅通知 / `reduce`=减仓 / `emergency_close`=全平 |
| `trigger_reason` | `margin_ratio_warning` / `margin_ratio_action` / `margin_ratio_emergency` / `manual` |
| `margin_ratio` | 触发时的保证金率（= 维持保证金 / 仓位名义价值） |
| `position_before/after` | JSON 快照，含 size / entry_price / unrealized_pnl，全平时 after 为 null |
| `status` | `pending`=动作发起 / `executed`=订单成交 / `failed`=下单失败 / `skipped`=单飞锁跳过 |

## 4. Redis 数据结构

### 4.1 仓位风控状态

```
Key: tenant:{tid}:position_risk:{iid}
Type: Hash
Fields:
  - side: long|short
  - size: 0.01000000
  - entry_price: 61000.00
  - leverage: 10
  - liquidation_price: 55200.00
  - maint_margin_rate: 0.0040
  - margin_ratio: 0.35        (当前保证金率)
  - last_action_at: 1729300000 (上次风控动作时间戳，用于 15min 去重)
  - lock: 1|0                 (单飞锁标志，TTL 由 SETEX 控制)
  - emergency: 1|0            (是否已触发全平，1 表示实例暂停)
```

### 4.2 单飞锁

```
Key: tenant:{tid}:position_risk:{iid}:lock
Type: String (SETEX)
Value: 1
TTL: 30s (config('risk.position.lock_ttl'))
```

减仓/全平动作前 `SET key 1 NX EX 30`，成功才执行；执行完不主动删除（靠 TTL 过期），防止同一 bar 内重复触发。

### 4.3 维持保证金率缓存

```
Key: futures:leverage_bracket:{symbol}
Type: String (JSON)
Value: [{"bracket":1,"initialMarginRate":0.10,"maintMarginRate":0.004,"cap":50000}, ...]
TTL: 86400 (24h)
```

从 `GET /fapi/v1/leverageBracket` 获取后缓存。

## 5. 配置扩展

### 5.1 config/risk.php 新增 position 块

```php
'position' => [
    'warning_margin_ratio'    => env('RISK_POSITION_WARNING', 0.50),
    'action_margin_ratio'     => env('RISK_POSITION_ACTION', 0.20),
    'emergency_margin_ratio'  => env('RISK_POSITION_EMERGENCY', 0.10),
    'reduce_ratio'            => env('RISK_POSITION_REDUCE_RATIO', 0.50),
    'lock_ttl'                => env('RISK_POSITION_LOCK_TTL', 30),
    'dedup_cooldown'          => env('RISK_POSITION_DEDUP', 900), // 15min
    'default_mmr' => [
        'BTCUSDT' => '0.0040',
        'ETHUSDT' => '0.0050',
    ],
],
```

### 5.2 config/exchange.php 新增 futures 块

```php
'exchanges' => [
    'binance' => [
        // ... 现有 spot 配置
        'futures' => [
            'rest_url' => env('EXCHANGE_BINANCE_FUTURES_URL', 'https://fapi.binance.com'),
            'futures_ws_url' => env('EXCHANGE_BINANCE_FUTURES_WS_URL', 'wss://fstream.binance.com/ws'),
            'testnet_url' => env('EXCHANGE_BINANCE_FUTURES_TESTNET_URL', 'https://testnet.binancefuture.com'),
            'testnet_ws_url' => env('EXCHANGE_BINANCE_FUTURES_TESTNET_WS_URL', 'wss://stream.binancefuture.com/ws'),
        ],
    ],
],
```

### 5.3 config/strategy.php 白名单回归

```php
'implemented' => ['spot_grid', 'spot_dca', 'futures_grid'],
```

### 5.4 config/plans.php 套餐白名单回归

```php
'pro' => [
    'features' => [
        // ...
        'strategies' => ['spot_grid', 'spot_dca', 'futures_grid'],
    ],
],
'enterprise' => [
    'features' => [
        // ...
        'strategies' => ['spot_grid', 'spot_dca', 'futures_grid'],
    ],
],
// free 不含 futures_grid
```

## 6. 数据迁移顺序

1. `strategy_instances` status 枚举追加 `paused_by_position_risk`
2. `strategy_orders` 新增 `leverage` / `reduce_only` 列 + type 枚举扩展
3. 新建 `position_risk_events` 表
4. `config/risk.php` / `config/exchange.php` / `config/strategy.php` / `config/plans.php` 同步更新

> 所有 migration 遵循项目惯例：`decimal(20,8)` 金额、`tenant_id` 外键级联、复合索引 `(tenant_id, instance_id, occurred_at)`。
