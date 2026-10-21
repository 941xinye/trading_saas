<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Domain\Archive\Stores\ClickHouseStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 生产探活：DB / Redis / ClickHouse / 队列。
 *
 * 判定口径：
 *  - database、redis 为 critical：任一失败 → overall=down（HTTP 503）
 *  - clickhouse、queue 为 optional：失败 → overall=degraded（HTTP 200）
 *  - 未部署 ClickHouse（health.checks.clickhouse=false）→ skipped，不参与判定
 *
 * 不返回异常原文，避免向公开探针端点泄露连接串；异常细节写日志。
 */
final class HealthCheckService
{
    /**
     * @return array{status:string,checked_at:string,checks:array<string,array<string,mixed>>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->probe('database', fn (): array => $this->checkDatabase()),
            'redis' => $this->probe('redis', fn (): array => $this->checkRedis()),
            'clickhouse' => $this->probe('clickhouse', fn (): array => $this->checkClickHouse()),
            'queue' => $this->probe('queue', fn (): array => $this->checkQueue()),
        ];

        $criticalFailed = $checks['database']['status'] === 'failed'
            || $checks['redis']['status'] === 'failed';

        $degraded = false;
        foreach ($checks as $check) {
            if ($check['status'] === 'failed') {
                $degraded = true;
            }
        }

        return [
            'status' => $criticalFailed ? 'down' : ($degraded ? 'degraded' : 'ok'),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    private function checkDatabase(): array
    {
        DB::connection()->select('select 1');

        return ['status' => 'ok'];
    }

    private function checkRedis(): array
    {
        $pong = Redis::connection()->ping();

        if ($pong === false || $pong === null) {
            return ['status' => 'failed', 'detail' => 'ping returned false'];
        }

        return ['status' => 'ok'];
    }

    private function checkClickHouse(): array
    {
        if (! (bool) config('health.checks.clickhouse', true)) {
            return ['status' => 'skipped', 'detail' => 'disabled by config'];
        }

        $config = (array) config('archive.ch');
        if (trim((string) ($config['host'] ?? '')) === '') {
            return ['status' => 'skipped', 'detail' => 'archive.ch.host is empty'];
        }

        // 探针使用短超时，避免归档库不可达时拖垮健康检查
        $config['timeout_ms'] = (int) config('health.timeout_ms', 1500);

        return (new ClickHouseStore($config))->ping()
            ? ['status' => 'ok']
            : ['status' => 'failed', 'detail' => 'ping failed'];
    }

    private function checkQueue(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['status' => 'failed', 'detail' => 'failed_jobs table missing'];
        }

        $failed24h = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDay())
            ->count();

        $threshold = (int) config('health.failed_jobs_threshold', 10);
        $status = $failed24h > $threshold ? 'degraded' : 'ok';
        $detail = null;

        $backlog = $this->queueBacklog();
        if ($backlog !== null
            && $backlog['total'] > (int) config('health.queue_backlog_threshold', 1000)) {
            $status = 'degraded';
            $detail = 'queue backlog exceeded threshold';
        }

        $result = [
            'status' => $status,
            'failed_last_24h' => $failed24h,
        ];
        if ($backlog !== null) {
            $result['backlog'] = $backlog;
        }
        if ($detail !== null) {
            $result['detail'] = $detail;
        }

        return $result;
    }

    /**
     * 队列积压：redis 读 list 长度，database 读 jobs 表；其他驱动返回 null。
     *
     * @return array{driver:string,total:int,queues:array<string,int>}|null
     */
    private function queueBacklog(): ?array
    {
        $driver = (string) config('queue.default');
        $names = (array) config('health.queue_names', ['default']);
        $queues = [];

        try {
            if ($driver === 'redis') {
                $connection = Redis::connection((string) config('queue.connections.redis.connection', 'default'));
                foreach ($names as $name) {
                    $queues[$name] = (int) $connection->llen('queues:'.$name);
                }
            } elseif ($driver === 'database' && Schema::hasTable('jobs')) {
                foreach ($names as $name) {
                    $queues[$name] = (int) DB::table('jobs')->where('queue', $name)->count();
                }
            } else {
                return null;
            }
        } catch (Throwable $e) {
            Log::warning('Health queue backlog check failed', ['error' => $e->getMessage()]);

            return null;
        }

        return [
            'driver' => $driver,
            'total' => array_sum($queues),
            'queues' => $queues,
        ];
    }

    /**
     * @param  callable():array<string,mixed>  $check
     * @return array<string,mixed>
     */
    private function probe(string $name, callable $check): array
    {
        $started = hrtime(true);

        try {
            $result = $check();
        } catch (Throwable $e) {
            Log::warning("Health check failed: {$name}", ['error' => $e->getMessage()]);

            $result = ['status' => 'failed', 'detail' => 'unavailable'];
        }

        $result['latency_ms'] = (int) round((hrtime(true) - $started) / 1_000_000);

        return $result;
    }
}
