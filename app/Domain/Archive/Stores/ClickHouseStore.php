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

        $orderParts = [];
        foreach ($orderBy as $column => $direction) {
            $orderParts[] = sprintf('%s %s', $this->escapeIdent($column), strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC');
        }

        $sql = sprintf(
            'SELECT * FROM %s.%s%s ORDER BY %s LIMIT %d OFFSET %d',
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
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = sprintf("ifNull(toString(%s), '')", $this->escapeIdent($column));
        }
        $canonical = implode(", '|', ", $parts);

        // 候选待核：toUInt64OrZero(str, 16) 的 base 参数在部署版本可用性；
        // 若不可用等价替换 reinterpretAsUInt64(reverse(unhex(substr(...))))（字节序对齐），集成测试断言兜底
        $rows = $this->selectRows(sprintf(
            "SELECT sum(toUInt64OrZero(substr(MD5(concat(%s)), 1, 16), 16)) AS h
             FROM %s.%s WHERE batch_id = %d",
            $canonical,
            $this->escapeIdent($this->config['database']),
            $this->escapeIdent($chTable),
            $batchId,
        ));

        return (string) ($rows[0]['h'] ?? '0');
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

        return array_values($decoded['data'] ?? []);
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
            $request = $body === null
                ? $this->client()->post($url)
                : $this->client()->withBody($body, 'text/plain; charset=UTF-8')->post($url);
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
