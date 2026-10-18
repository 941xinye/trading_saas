# Contract: HistoricalQuery（统一历史查询·内部服务层契约）

**Feature**: 004-clickhouse-historical-archive | **Date**: 2026-10-18
**边界**（clarify 决定 2）：仅平台内部服务层 PHP 契约，**不暴露 HTTP API**；首批消费端 P10 回测 / P7 DSL 回测预览出现时再议 HTTP 面。
**落点**: `app/Domain/Archive/Contracts/HistoricalQuery.php` + `app/Domain/Archive/Dto/`。

---

## 1. 接口签名

```php
namespace App\Domain\Archive\Contracts;

use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Archive\Dto\HistoricalQueryResult;

interface HistoricalQuery
{
    /**
     * 统一历史查询：按数据类型 + UTC 时间范围 [from, to) + 可选过滤查历史，
     * 自动路由冷热两层并合并为完整有序结果，调用方不感知数据所在层。
     *
     * @throws ArchiveStorageUnavailableException 历史层不可用（原样上抛，不吞不改写）
     * @throws ArchiveQueryLimitException         pageSize 超上限 / 游标非法
     * @throws \InvalidArgumentException          参数非法（类型不支持的过滤字段、from >= to 等）
     */
    public function query(HistoricalQueryRequest $request): HistoricalQueryResult;
}
```

配套类型（同 Domain）：

```php
enum ArchiveDataType: string
{
    case KlineBar        = 'kline_bar';         // public（kline_bars / ch_kline_bars）
    case Signal          = 'signal';            // tenant （signals / ch_signals）
    case StrategyOrder   = 'strategy_order';    // tenant （strategy_orders / ch_strategy_orders）
    case LiquidationEvent = 'liquidation_event'; // public（liquidation_events / ch_liquidation_events）
}

final class HistoricalQueryRequest   // 不可变 DTO，字段与校验规则见 data-model.md §4
final class HistoricalQueryResult    // rows（列名=>字符串 字典）+ nextPageCursor + servedFrom
```

## 2. 查询语义（FR-006/FR-007/FR-011 落地）

| 维度 | 契约 |
|---|---|
| 时间范围 | `[from, to)` 左闭右开，全 UTC；每类型时间轴固定：kline_bar=`open_time`、signal=`triggered_at`、strategy_order=`created_at`、liquidation_event=`event_ts`（R-005） |
| 冷热路由 | 基准 `archive_watermarks.applied_watermark`：`to <= W` 纯冷；`from >= W` 纯热；跨界两侧查 + `(时间列, id)` 归并排序 + 自然键去重（in-flight 批次行两侧同现，`id` 相同保留一条）（R-007） |
| 排序 | `(时间列 ASC, id ASC)`，全结果集有序，跨页游标续序不重不漏 |
| 分页 | 游标 = `(最后行时间列值, 最后行 id)` 的 base64 不透明串；`nextPageCursor = null` 表示无更多页 |
| 行数上限 | 单页默认 1000；上限 config `archive.query_max_rows`（默认 50000）；请求超限抛 `ArchiveQueryLimitException`，**不静默截断**（FR-011） |
| 行形态 | 每行 = `array<string,string|null>`：Decimal/时间/整数全字符串（Decimal 带引号回读，R-004），消费端 bcmath 直用不做类型推断 |
| servedFrom | `HOT` / `COLD` / `MERGED` 观测字段，仅测试与运维观测，不参与业务逻辑 |

## 3. 过滤字段按类型开放（错用即抛 InvalidArgumentException）

| 过滤 | KlineBar | Signal | StrategyOrder | LiquidationEvent |
|---|---|---|---|---|
| symbol | ✓ | ✓ | ✓ | ✓ |
| exchangeCode | ✓ | ✗ | ✗ | ✓ |
| interval | ✓ | ✗ | ✗ | ✗ |
| tenantId 字段 | **不存在**（隔离下沉，见 §4） | | | |

## 4. 租户可见性域（FR-007 / US3 落地，R-008）

- **tenant 类型**（Signal / StrategyOrder）：Service 构造查询时强制 `TenantContext::requireTenantId()` 注入 `tenant_id = ?`（热侧 Eloquent 查询保持 TenantScope 自动注入，冷侧 CH 查询由 ArchiveType 构造同谓词）。`HistoricalQueryRequest` **没有 tenantId 公开字段**——越权通道物理不存在（US3 场景 2 的实现形态：不存在"指定他人租户"的表达能力）。无租户上下文调用即抛 RuntimeException（TenantContext 既有契约）。
- **public 类型**（KlineBar / LiquidationEvent）：平台公共市场数据，任何上下文可查，与租户无关。
- 平台全域跨租户读取不在本期契约内（`TenantContext::runWithoutTenant()` 显式提权留待真实平台级消费端出现时评估）。

## 5. 失败语义（FR-008 / US4 落地，R-009）

- CH 不可达/超时/5xx → `ArchiveStorageUnavailableException`（携带原因），**原样上抛**；调用方决定降级展示（"历史层暂不可用"）。纯热查询（`from >= W`）不触碰 CH，历史层故障零影响（US4 场景 2 的机制性保证）。
- 不存在"吞异常返回空结果"路径——空结果与故障必须可区分。

## 6. 实现边界

- 唯一实现：`app/Domain/Archive/Services/HistoricalQueryService.php`（implements `HistoricalQuery`）。
- 热侧读取复用既有 Eloquent 模型（KlineBar/Signal/StrategyOrder/LiquidationEvent），**既有模型与 P1-P5 代码零改动**；冷侧经 `ArchiveStore` 契约（CH 实现可替换、测试可 fake）。
- Octane 上下文调用注意：CH 查询为阻塞 HTTP（research R-002），单页目标 p95 <100ms，与既有 exchange REST 调用同模式；禁入 market:feed 行情协程进程（constitution III 边界）。
