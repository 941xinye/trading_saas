<?php

declare(strict_types=1);

namespace App\Domain\Archive\Stores;

use App\Domain\Archive\Contracts\ArchiveStore;
use App\Domain\Archive\Exceptions\ArchiveStorageUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * ClickHouse 存储实现（spec 004 R-002：零新增 Composer 依赖，Http facade 直连 HTTP 接口）。
 *
 * - 全部语句经 POST /?query=...（body 仅 JSONEachRow 写入场景）；
 * - 鉴权 X-ClickHouse-User / X-ClickHouse-Key（R-001 env 键集）；
 * - 读取恒带 output_format_json_quote_decimals=1 / output_format_json_quote_64bit_integers=1
 *   （constitution II：Decimal/UInt64 回读为字符串，规避 JSON number >2^53 丢精度）；
 * - HTTP 层异常统一包装 ArchiveStorageUnavailableException（R-009）。
 *
 * 底层细节收敛在本单类内，对上只暴露 ArchiveStore 契约。
 */
final class ClickHouseStore implements ArchiveStore
{
    private const READ_SETTINGS = 'output_format_json_quote_decimals=1&output_format_json_quote_64bit_integers=1';

    /** @var array<string, list<array{name: string, scale: int|null}>> 表名 => 列元数据（position 序），进程内缓存 */
    private array $columnMetaCache = [];

    public function __construct(private readonly array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self(config('archive.ch'));
    }

    public function ping(): bool
    {
        try {
            $response = $this->client()->get($this->baseUrl() . '/ping');
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful() && trim($response->body()) === 'Ok.';
    }

    public function createSchema(): array
    {
        $db = $this->escapeIdent($this->config['database']);
        $statements = [
            sprintf('CREATE DATABASE IF NOT EXISTS %s', $db),
            ...array_map(
                fn (string $ddl): string => str_replace('__DB__', $db, $ddl),
                $this->tableDdls(),
            ),
        ];

        foreach ($statements as $statement) {
            $this->send($this->queryUrl($statement), null);
        }

        return $statements;
    }

    public function insertRows(string $chTable, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = json_encode(
                $row,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        $sql = sprintf(
            'INSERT INTO %s.%s FORMAT JSONEachRow',
            $this->escapeIdent($this->config['database']),
            $this->escapeIdent($chTable),
        );
        $this->send($this->queryUrl($sql), implode("\n", $lines) . "\n");
    }

    public function countByBatch(string $chTable, int $batchId): int
    {
        $rows = $this->selectRows(sprintf(
            'SELECT count() AS c FROM %s.%s WHERE batch_id = %d',
            $this->escapeIdent($this->config['database']),
            $this->escapeIdent($chTable),
            $batchId,
        ));

        return (int) ($rows[0]['c'] ?? 0);
    }

    public function deleteByBatch(string $chTable, int $batchId): void
    {
        // lightweight delete（CH 22.8+ / 23.x GA，R-003 候选待核项在集成测试断言即时可见性）
        $this->send($this->queryUrl(sprintf(
            'DELETE FROM %s.%s WHERE batch_id = %d',
            $this->escapeIdent($this->config['database']),
            $this->escapeIdent($chTable),
            $batchId,
        )), null);
    }

    public function fetchRows(
        string $chTable,
        array $filters,
        string $timeColumn,
        ?string $timeFromUtc,
        ?string $timeToUtc,
        array $orderBy,
        int $limit,
        int $offset = 0,
        ?array $keysetAfter = null,
    ): array {
        $where = [];
        foreach ($filters as $column => $value) {
            if ($value === null) {
                $where[] = sprintf('%s IS NULL', $this->escapeIdent($column));

                continue;
            }
            $where[] = sprintf('%s = %s', $this->escapeIdent($column), $this->escapeValue($value));
        }
        if ($timeFromUtc !== null) {
            $where[] = sprintf('%s >= %s', $this->escapeIdent($timeColumn), $this->escapeValue($timeFromUtc));
        }
        if ($timeToUtc !== null) {
            $where[] = sprintf('%s < %s', $this->escapeIdent($timeColumn), $this->escapeValue($timeToUtc));
        }
        if ($keysetAfter !== null) {
            // keyset 游标（US2 分页）：(时间列, id) 严格大于
            $where[] = sprintf(
                '(%s > %s OR (%s = %s AND id > %d))',
                $this->escapeIdent($timeColumn),
                $this->escapeValue($keysetAfter['time']),
                $this->escapeIdent($timeColumn),
                $this->escapeValue($keysetAfter['time']),
                (int) $keysetAfter['id'],
            );
        }

        $orderParts = [];
        foreach ($orderBy as $column => $direction) {
            $orderParts[] = sprintf('%s %s', $this->escapeIdent($column), strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC');
        }

        // 坑④延伸：CH 26 JSON 输出同样裁 Decimal 尾零（"100.1"），读回必须 toDecimalString
        // 保标度，否则冷热合并查询（SC-001）与热侧 MySQL 字符串（'100.10000000'）不同形。
        $selectParts = [];
        foreach ($this->columnMeta($chTable) as $meta) {
            $selectParts[] = $meta['scale'] !== null
                ? sprintf('toDecimalString(%s, %d) AS %s', $this->escapeIdent($meta['name']), $meta['scale'], $this->escapeIdent($meta['name']))
                : $this->escapeIdent($meta['name']);
        }

        $sql = sprintf(
            'SELECT %s FROM %s.%s%s ORDER BY %s LIMIT %d OFFSET %d',
            implode(', ', $selectParts),
            $this->escapeIdent($this->config['database']),
            $this->escapeIdent($chTable),
            $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            $orderParts === [] ? 'id ASC' : implode(', ', $orderParts),
            $limit,
            $offset,
        );

        return $this->selectRows($sql);
    }

    public function hashSum(string $chTable, int $batchId, array $columns): string
    {
        // 与 RowNormalizer::canonicalRow 同构：列序一致、'|' 分隔、null → ''（ifNull）
        $scales = [];
        foreach ($this->columnMeta($chTable) as $meta) {
            if ($meta['scale'] !== null) {
                $scales[$meta['name']] = $meta['scale'];
            }
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = isset($scales[$column])
                ? sprintf("ifNull(toDecimalString(%s, %d), '')", $this->escapeIdent($column), $scales[$column])
                : sprintf("ifNull(toString(%s), '')", $this->escapeIdent($column));
        }
        $canonical = implode(", '|', ", $parts);

        // hex→UInt64 最终式（T031 四踩坑实测锁定）：
        // ① toUInt64OrZero 无 base 参数（Code:42）；② CH 26.x MD5() 返回 16 字节二进制
        //    FixedString 而非 hex 字符串，substr 直接按字节取；③ sum() 整数溢出**饱和**为
        //    2^64-1，必须用回绕语义的 sumWithOverflow 才与 PHP bcadd+bcmod 2^64 同构；
        // ④ toString(Decimal) 裁尾零（'100.00000000' → '100'），与 PHP 侧 MySQL 字符串原值
        //    （保标度）不同构，Decimal 列必须 toDecimalString(col, 列自身标度)。
        // substring(MD5(...),1,8) 取前 8 字节（= hex 前 16 位），reverse 转小端后 reinterpret，
        // 与 RowNormalizer 黄金向量逐位一致。
        $rows = $this->selectRows(sprintf(
            "SELECT sumWithOverflow(reinterpretAsUInt64(reverse(substring(MD5(concat(%s)), 1, 8)))) AS h
             FROM %s.%s WHERE batch_id = %d",
            $canonical,
            $this->escapeIdent($this->config['database']),
            $this->escapeIdent($chTable),
            $batchId,
        ));

        return (string) ($rows[0]['h'] ?? '0');
    }

    /**
     * 列元数据（position 序）。两处消费：hashSum 的 toDecimalString 需显式 S；
     * fetchRows 需全列名 + Decimal 标度做保标度读回。
     *
     * @return list<array{name: string, scale: int|null}> scale 仅 Decimal 列非 null
     */
    private function columnMeta(string $chTable): array
    {
        return $this->columnMetaCache[$chTable] ??= $this->fetchColumnMeta($chTable);
    }

    /**
     * @return list<array{name: string, scale: int|null}>
     */
    private function fetchColumnMeta(string $chTable): array
    {
        $rows = $this->selectRows(sprintf(
            'SELECT name, type FROM system.columns WHERE database = %s AND table = %s ORDER BY position',
            $this->escapeValue((string) $this->config['database']),
            $this->escapeValue($chTable),
        ));

        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'scale' => preg_match('/Decimal\(\d+,\s*(\d+)\)/', (string) $row['type'], $m) === 1 ? (int) $m[1] : null,
        ], $rows);
    }

    /**
     * 执行 SELECT（FORMAT JSON + 引号回读设置），返回行集（列名 => 字符串/null）。
     *
     * @return list<array<string, string|null>>
     */
    private function selectRows(string $sql): array
    {
        $body = $this->send($this->queryUrl($sql . ' FORMAT JSON'), null);

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('ClickHouse 响应 JSON 解析失败：' . $e->getMessage(), 0, $e);
        }

        // 契约口径：值一律 string|null。quote_64bit 只罩 UInt64/Decimal，
        // UInt8/16/32（trades_count、reduce_only 等）仍以 JSON number 回来 → 归一成字符串。
        return array_map(static function (array $row): array {
            foreach ($row as $key => $value) {
                if (is_int($value) || is_float($value)) {
                    $row[$key] = (string) $value;
                }
            }

            return $row;
        }, array_values($decoded['data'] ?? []));
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'X-ClickHouse-User' => (string) $this->config['user'],
            'X-ClickHouse-Key' => (string) $this->config['password'],
        ])
            ->timeout(max(1, (int) $this->config['timeout_ms'] / 1000))
            ->connectTimeout(5);
    }

    private function send(string $url, ?string $body): string
    {
        try {
            // 坑：Laravel Http post($url) 不带 withBody 会把默认空数组编码成 "[]" JSON body，
            // CH 会把 body 拼接到 query 参数后解析（DDL 变 "CREATE ...\n[]" 语法错误，T031 实测 HTTP 400）。
            // 无 body 请求必须显式 withBody('')。
            $request = $this->client()
                ->withBody($body ?? '', 'text/plain; charset=UTF-8')
                ->post($url);
        } catch (ConnectionException $e) {
            throw new ArchiveStorageUnavailableException('ClickHouse 连接失败：' . $e->getMessage(), 0, $e);
        }

        if (! $request->successful()) {
            throw new ArchiveStorageUnavailableException(sprintf(
                'ClickHouse HTTP %d：%s',
                $request->status(),
                mb_substr(trim($request->body()), 0, 300),
            ));
        }

        return $request->body();
    }

    private function baseUrl(): string
    {
        return sprintf('http://%s:%d', $this->config['host'], (int) $this->config['port']);
    }

    private function queryUrl(string $sql): string
    {
        return $this->baseUrl() . '/?' . self::READ_SETTINGS . '&query=' . rawurlencode($sql);
    }

    /**
     * 标识符转义（反引号）——列名/表名全部来自契约常量，防御性转义。
     */
    private function escapeIdent(string $ident): string
    {
        return '`' . str_replace('`', '``', $ident) . '`';
    }

    /**
     * 值转义（字符串单引号 + 反斜杠；整数强转）。
     */
    private function escapeValue(string|int $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (ctype_digit($value)) {
            return $value; // 数字串（id/tenant_id 等）直接字面量
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * 四张冷表 DDL（唯一事实源 = contracts/clickhouse-schema-contract.md，逐字镜像）。
     *
     * @return list<string>
     */
    private function tableDdls(): array
    {
        return [
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS __DB__.`ch_kline_bars`
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
            ORDER BY (exchange_code, symbol, interval, open_time, id)
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS __DB__.`ch_signals`
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
            ORDER BY (tenant_id, symbol, triggered_at, id)
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS __DB__.`ch_strategy_orders`
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
            ORDER BY (tenant_id, created_at, id)
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS __DB__.`ch_liquidation_events`
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
            ORDER BY (symbol, event_ts, id)
            SQL,
        ];
    }
}
