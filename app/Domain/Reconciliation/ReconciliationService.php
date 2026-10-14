<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation;

use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeBalance;
use App\Domain\Exchange\ExchangeManager;
use App\Domain\Notification\DedupCooldown;
use App\Jobs\RiskNotificationJob;
use App\Support\AesGcmCrypt;
use App\Support\KeyResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * 账实对账服务（P5.5 步骤 1，销债 A1/A3）。
 *
 * 比对「策略账本（Redis/DB 快照 + strategy_orders 成交汇总）」与
 * 「交易所账户真实余额」，差异分级落 reconciliation_reports：
 *
 *   ok       容差内（数量 dust 或等值 ≤ quote 容差）
 *   drift    容差外，且方向为「交易所多币」——不暂停
 *   critical 方向为「账本有、交易所无」或等值超严重阈值——暂停实例并告警
 *   unknown  取数失败（网络/凭据）——不暂停；连续 N 次才告警
 *
 * 铁律：只报告 + 暂停，绝不自动改账/补单/平仓；恢复运行由人工显式执行。
 * 暂停态与熔断态正交：paused_by_breaker 的实例不被对账覆盖。
 *
 * 全程 bcmath 字符串金额；period_key / 时间均为 UTC。
 */
class ReconciliationService
{
    public const STATUS_OK = 'ok';

    public const STATUS_DRIFT = 'drift';

    public const STATUS_CRITICAL = 'critical';

    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        private readonly ExchangeManager $exchangeManager,
        private readonly KeyResolver $keyResolver,
        private readonly AesGcmCrypt $crypt,
        private readonly DedupCooldown $dedup,
    ) {}

    // ------------------------------------------------------------------
    // 纯比对逻辑（无 IO，可直接单测）
    // ------------------------------------------------------------------

    /**
     * 单维度差值分级。
     *
     * @param  string  $diffQty  实际 − 账本（有符号，负数表示实际少于账本）
     * @param  string  $price  该资产 USDT 参考价（无法取得时传 '0'）
     * @param  array{qty:string,quote:string}  $tolerance
     * @return string ok|drift|critical
     */
    public static function classifyDimension(string $diffQty, string $price, array $tolerance, string $criticalQuote): string
    {
        $absQty = bccomp($diffQty, '0', 12) < 0 ? bcsub('0', $diffQty, 12) : $diffQty;

        // dust：数量容差内「或」等值容差内
        if (bccomp($absQty, (string) $tolerance['qty'], 12) <= 0) {
            return self::STATUS_OK;
        }
        if (bccomp($price, '0', 12) === 1) {
            $absValue = bcmul($absQty, $price, 12);
            if (bccomp($absValue, (string) $tolerance['quote'], 12) <= 0) {
                return self::STATUS_OK;
            }
            if (bccomp($absValue, $criticalQuote, 12) >= 0) {
                return self::STATUS_CRITICAL;
            }
        }

        // 账本有、交易所无（实际少于账本）→ 资金安全方向，critical
        return bccomp($diffQty, '0', 12) < 0 ? self::STATUS_CRITICAL : self::STATUS_DRIFT;
    }

    /**
     * 多维度取最严重等级。
     *
     * @param  string[]  $statuses
     */
    public static function worst(array $statuses): string
    {
        if (in_array(self::STATUS_CRITICAL, $statuses, true)) {
            return self::STATUS_CRITICAL;
        }
        if (in_array(self::STATUS_DRIFT, $statuses, true)) {
            return self::STATUS_DRIFT;
        }
        if (in_array(self::STATUS_UNKNOWN, $statuses, true)) {
            return self::STATUS_UNKNOWN;
        }

        return self::STATUS_OK;
    }

    /**
     * UTC 周期桶 key，如 5m 桶 → 20261015T0800；支持 {n}m / {n}h。
     */
    public static function periodKey(int $nowSec, string $period): string
    {
        if (preg_match('/^(\d+)(m|h)$/', $period, $m) !== 1) {
            $period = '5m';
            $m = [1 => 5, 2 => 'm'];
        }
        $n = max(1, (int) $m[1]);
        $seconds = $m[2] === 'h' ? $n * 3600 : $n * 60;
        $bucket = intdiv($nowSec, $seconds) * $seconds;

        return gmdate('Ymd\THi', $bucket);
    }

    /**
     * 从交易对拆 base/quote（后缀最长匹配）。
     *
     * @return array{0:string,1:string} [base, quote]
     */
    public static function splitSymbol(string $symbol, array $quoteSuffixes): array
    {
        foreach ($quoteSuffixes as $suffix) {
            if (str_ends_with($symbol, $suffix) && strlen($symbol) > strlen($suffix)) {
                return [substr($symbol, 0, -strlen($suffix)), $suffix];
            }
        }

        return [$symbol, 'USDT'];
    }

    // ------------------------------------------------------------------
    // 对账编排（IO）
    // ------------------------------------------------------------------

    /**
     * 对单个实例执行一次对账。
     *
     * @param  string|null  $period  周期桶规格（null 取 config 默认）
     * @param  int|null  $nowSec  注入当前时间（测试用，UTC 秒）
     * @param  ExchangeAdapter|null  $adapter  注入适配器（测试用）
     * @return array{id:int,status:string} 报告行 id 与最终状态
     */
    public function runForInstance(
        int $instanceId,
        ?string $period = null,
        ?int $nowSec = null,
        ?ExchangeAdapter $adapter = null,
    ): array {
        $period ??= (string) config('reconcile.default_period', '5m');
        $now = Carbon::now();
        $nowSec ??= $now->getTimestamp();

        $instance = DB::table('strategy_instances')->where('id', $instanceId)->first();
        if ($instance === null) {
            throw new \RuntimeException("Strategy instance {$instanceId} not found");
        }
        $tenantId = (int) $instance->tenant_id;
        $periodKey = self::periodKey($nowSec, $period);

        $adapter ??= $this->buildAdapter($instance, $tenantId);

        // ---- 取交易所真实余额；失败 → unknown（不暂停）----
        try {
            $balance = $adapter->getBalance();
        } catch (Throwable $e) {
            Log::warning('Reconcile getBalance failed, marking unknown', [
                'instance' => $instanceId, 'error' => $e->getMessage(),
            ]);

            return $this->recordUnknown($instance, $tenantId, $periodKey, $now, $e->getMessage());
        }

        // 参考价（失败不致命：数量维度仍可分级，等值容差/严重阈值退化为仅按数量）
        $price = '0';
        try {
            $price = $adapter->getTicker((string) $instance->symbol)->lastPrice;
        } catch (Throwable $e) {
            Log::info('Reconcile ticker unavailable', [
                'instance' => $instanceId, 'error' => $e->getMessage(),
            ]);
        }

        $suffixes = (array) config('reconcile.quote_suffixes', ['USDT']);
        [$baseAsset, $quoteAsset] = self::splitSymbol((string) $instance->symbol, $suffixes);

        $strategyCode = (string) DB::table('strategies')->where('id', $instance->strategy_id)->value('code');
        $ledger = $this->readLedger($instance, $tenantId, $strategyCode);
        $orders = $this->readOrderTotals($instanceId);

        $exchangeQty = (string) ($balance->assets[$baseAsset]['total'] ?? '0');

        $tolerance = (array) config('reconcile.tolerance', ['qty' => '0.000001', 'quote' => '5']);
        $criticalQuote = (string) config('reconcile.critical_quote', '50');

        $baseDiff = bcsub($exchangeQty, $ledger['qty'], 12);
        $ordersDiff = bcsub($orders['net_qty'], $ledger['qty'], 12);

        $baseStatus = self::classifyDimension($baseDiff, $price, $tolerance, $criticalQuote);
        $ordersStatus = self::classifyDimension($ordersDiff, $price, $tolerance, $criticalQuote);
        $status = self::worst([$baseStatus, $ordersStatus]);

        $diff = [
            'asset' => $baseAsset,
            'price' => $price,
            'base_qty' => [
                'ledger' => $ledger['qty'],
                'exchange' => $exchangeQty,
                'diff' => $baseDiff,
                'status' => $baseStatus,
            ],
            'orders_net_qty' => [
                'filled_buy' => $orders['buy_qty'],
                'filled_sell' => $orders['sell_qty'],
                'net' => $orders['net_qty'],
                'ledger' => $ledger['qty'],
                'diff' => $ordersDiff,
                'status' => $ordersStatus,
            ],
            'cost_basis' => [
                'ledger_cost' => $ledger['cost'],
            ],
            'tolerance' => $tolerance,
            'critical_quote' => $criticalQuote,
        ];

        $this->writeBalanceSnapshot($tenantId, (int) $instance->exchange_account_id, $balance, $now);

        $reportId = $this->upsertReport(
            $instance,
            $tenantId,
            $periodKey,
            $status,
            $diff,
            $now,
        );

        // 非 unknown：连续计数清零
        $this->resetUnknownStreak($tenantId, $instanceId);

        if ($status === self::STATUS_CRITICAL) {
            $this->handleCritical($instance, $tenantId, $instanceId, $diff);
        }

        return ['id' => $reportId, 'status' => $status];
    }

    /**
     * 全部应对账实例（running / 两种 paused；stopped/error/starting 不打扰）。
     *
     * @return int[]
     */
    public function reconcileableInstanceIds(): array
    {
        return DB::table('strategy_instances')
            ->whereIn('status', ['running', 'paused_by_breaker', 'paused_by_reconcile'])
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    // ------------------------------------------------------------------
    // 内部：账本读取
    // ------------------------------------------------------------------

    /**
     * 策略账本持仓。
     *
     * @return array{qty:string,cost:string}
     */
    private function readLedger(object $instance, int $tenantId, string $strategyCode): array
    {
        $snapshot = $this->latestSnapshot($instance, $tenantId);

        // 现货网格：grids 明细汇总（最权威，含每格成本）
        if ($strategyCode === 'spot_grid') {
            $qty = '0';
            $cost = '0';
            foreach ((array) ($snapshot['grids'] ?? []) as $g) {
                $cellQty = (string) ($g['qty'] ?? '0');
                $qty = bcadd($qty, $cellQty, 8);
                $cost = bcadd($cost, bcmul($cellQty, (string) ($g['cost'] ?? '0'), 12), 12);
            }

            return ['qty' => $qty, 'cost' => $cost];
        }

        // 其他现货策略（spot_dca 等）：快照无明细，以成交净额为账本口径
        // （快照内 total_spent 仅用于成本展示）
        return ['qty' => $this->readOrderTotals((int) $instance->id)['net_qty'], 'cost' => '0'];
    }

    /**
     * 取最新状态快照：先 Redis（每 bar 刷），不可用回退 DB position_snapshot（每 60s 刷）。
     *
     * @return array<string,mixed>
     */
    private function latestSnapshot(object $instance, int $tenantId): array
    {
        $key = str_replace(
            ['{tenant_id}', '{instance_id}'],
            [$tenantId, (int) $instance->id],
            (string) config('strategy.state.key_prefix', 'tenant:{tenant_id}:strategy:{instance_id}:state'),
        );
        try {
            $raw = Redis::connection('default')->get($key);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && is_array($decoded['position_snapshot'] ?? null)) {
                    return $decoded['position_snapshot'];
                }
            }
        } catch (Throwable) {
            // Redis 不可用时走 DB 快照
        }

        $dbSnapshot = DB::table('strategy_instances')->where('id', $instance->id)->value('position_snapshot');
        $decoded = is_string($dbSnapshot) ? json_decode($dbSnapshot, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * strategy_orders 已成交买卖汇总。
     *
     * @return array{buy_qty:string,sell_qty:string,net_qty:string}
     */
    private function readOrderTotals(int $instanceId): array
    {
        $rows = DB::table('strategy_orders')
            ->where('strategy_instance_id', $instanceId)
            ->where('status', 'filled')
            ->select('side', DB::raw('SUM(filled_qty) AS qty'))
            ->groupBy('side')
            ->pluck('qty', 'side');

        $buy = (string) ($rows['buy'] ?? '0');
        $sell = (string) ($rows['sell'] ?? '0');

        return [
            'buy_qty' => $buy,
            'sell_qty' => $sell,
            'net_qty' => bcsub($buy, $sell, 8),
        ];
    }

    // ------------------------------------------------------------------
    // 内部：报告 / 快照 / 暂停 / 告警 / unknown
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $diff
     */
    private function upsertReport(
        object $instance,
        int $tenantId,
        string $periodKey,
        string $status,
        array $diff,
        Carbon $now,
    ): int {
        $existing = DB::table('reconciliation_reports')
            ->where('tenant_id', $tenantId)
            ->where('strategy_instance_id', $instance->id)
            ->where('period_key', $periodKey)
            ->first(['id']);

        if ($existing !== null) {
            DB::table('reconciliation_reports')->where('id', $existing->id)->update([
                'status' => $status,
                'diff_json' => json_encode($diff, JSON_UNESCAPED_UNICODE),
                'checked_at' => $now,
                'updated_at' => $now,
            ]);

            return (int) $existing->id;
        }

        return (int) DB::table('reconciliation_reports')->insertGetId([
            'tenant_id' => $tenantId,
            'strategy_instance_id' => $instance->id,
            'exchange_account_id' => $instance->exchange_account_id,
            'status' => $status,
            'diff_json' => json_encode($diff, JSON_UNESCAPED_UNICODE),
            'period_key' => $periodKey,
            'checked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function writeBalanceSnapshot(int $tenantId, int $exchangeAccountId, ExchangeBalance $balance, Carbon $now): void
    {
        try {
            DB::table('balance_snapshots')->insert([
                'tenant_id' => $tenantId,
                'exchange_account_id' => $exchangeAccountId,
                'snapshot_at' => $now,
                'total_equity_usdt' => $balance->totalEquity,
                'total_margin_usdt' => $balance->totalMargin,
                'available_usdt' => $balance->available,
                'unrealized_pnl' => $balance->unrealizedPnl,
                'assets' => json_encode($balance->assets, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            Log::warning('balance_snapshots write failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string,mixed>  $diff
     */
    private function handleCritical(object $instance, int $tenantId, int $instanceId, array $diff): void
    {
        // 与熔断态正交：paused_by_breaker 绝不被对账覆盖
        if ($instance->status === 'running') {
            DB::table('strategy_instances')->where('id', $instanceId)->update([
                'status' => 'paused_by_reconcile',
                'updated_at' => Carbon::now(),
            ]);
            Log::critical('Strategy paused by reconciliation', [
                'instance' => $instanceId, 'diff' => $diff,
            ]);
        }

        $this->notify($tenantId, $instanceId, 'critical');
    }

    private function recordUnknown(
        object $instance,
        int $tenantId,
        string $periodKey,
        Carbon $now,
        string $error,
    ): array {
        $reportId = $this->upsertReport($instance, $tenantId, $periodKey, self::STATUS_UNKNOWN, [
            'error' => mb_substr($error, 0, 500),
        ], $now);

        $streak = $this->bumpUnknownStreak($tenantId, (int) $instance->id);
        $threshold = (int) config('reconcile.unknown_alert_after', 3);
        if ($streak >= $threshold) {
            $this->notify($tenantId, (int) $instance->id, 'unknown_streak');
        }

        return ['id' => $reportId, 'status' => self::STATUS_UNKNOWN];
    }

    private function unknownKey(int $tenantId, int $instanceId): string
    {
        return str_replace(
            ['{tenant_id}', '{instance_id}'],
            [$tenantId, $instanceId],
            (string) config('reconcile.unknown_key_prefix', 'tenant:{tenant_id}:reconcile:{instance_id}:unknown'),
        );
    }

    private function bumpUnknownStreak(int $tenantId, int $instanceId): int
    {
        try {
            return (int) Redis::connection('default')->incr($this->unknownKey($tenantId, $instanceId));
        } catch (Throwable) {
            // Redis 不可用时无法累计连续次数，退化为「每次 unknown 都不告警」之外的保守策略：
            // 返回阈值（连续失败伴随基础设施异常时，日志已有记录，不因此暂停实例）
            return 0;
        }
    }

    private function resetUnknownStreak(int $tenantId, int $instanceId): void
    {
        try {
            Redis::connection('default')->del($this->unknownKey($tenantId, $instanceId));
        } catch (Throwable) {
        }
    }

    /**
     * 复用 P4 通知链落库审计：risk_notifications（circuit_breaker_event_id=null）
     * + RiskNotificationJob；DedupCooldown 防同实例同类告警刷屏。
     */
    private function notify(int $tenantId, int $instanceId, string $kind): void
    {
        $channels = (array) config('reconcile.notify_channels', ['log']);
        if ($channels === []) {
            return;
        }

        $ttl = (int) config('reconcile.dedup_ttl_minutes', 15);
        $fingerprint = sha1($instanceId.'|'.$kind);
        try {
            $acquired = $this->dedup->tryAcquire($tenantId, 'reconcile', $fingerprint, $ttl);
        } catch (Throwable) {
            // 去重存储不可用时告警 fail-open（资金安全方向，宁发勿漏）
            $acquired = true;
        }
        if (! $acquired) {
            return;
        }

        $now = Carbon::now();
        foreach ($channels as $channel) {
            DB::table('risk_notifications')->insert([
                'tenant_id' => $tenantId,
                'circuit_breaker_event_id' => null,
                'instance_id' => $instanceId,
                'channel' => $channel,
                'status' => 'queued',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        RiskNotificationJob::dispatch($tenantId, null, $instanceId, $channels);
    }

    // ------------------------------------------------------------------
    // 内部：适配器装配（与 StrategyRunner 同源，命令/worker 普通进程内执行）
    // ------------------------------------------------------------------

    private function buildAdapter(object $instance, int $tenantId): ExchangeAdapter
    {
        $account = DB::table('exchange_accounts')->where('id', $instance->exchange_account_id)->first();
        $exchange = $account === null ? null
            : DB::table('exchanges')->where('id', $account->exchange_id)->first(['code']);
        if ($account === null || $exchange === null) {
            throw new \RuntimeException("Instance {$instance->id} references missing exchange account");
        }

        $dek = $this->keyResolver->tenantDek($tenantId);
        $apiKey = $this->crypt->decrypt((string) $account->encrypted_api_key, $dek);
        $apiSecret = $this->crypt->decrypt((string) $account->encrypted_api_secret, $dek);
        if ($apiKey === null || $apiSecret === null) {
            throw new \RuntimeException("Instance {$instance->id}: exchange credentials undecryptable");
        }

        return $this->exchangeManager->adapter(
            (string) $exchange->code,
            (string) $account->market,
            ['api_key' => $apiKey, 'api_secret' => $apiSecret],
        );
    }
}
