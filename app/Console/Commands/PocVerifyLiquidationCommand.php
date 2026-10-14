<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Exchange\Contracts\LiquidationEvent;
use App\Domain\Liquidation\LiquidationBucketPolicy;
use App\Domain\Liquidation\LiquidationClusterer;
use App\Domain\Liquidation\LiquidationMapService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * P5 POC 离线全链路断言（15 项）：
 *   1-2.  LiquidationEvent DTO 解析 + value_usd 计算
 *   3.   桶宽策略 0.1% 边界（60000/60059 同桶，60060 异桶）
 *   4-5. Clusterer::ingest 单条/批量写入 ZCARD 计数
 *   6-7. Clusterer::snapshot 结构 + 按 total_qty desc 排序
 *   8.   HASH bucket qty 与 SUM(原始 qty) 一致
 *   9.   evictExpired 后空桶 HDEL 自动清理
 *  10.   evictExpired 清过期事件后 ZCARD 减少
 *  11.   window 5m/15m/60m 三档独立 ZSET 不串扰
 *  12.   同 symbol + 不同 side (BUY/SELL) 独立
 *  13.   跨 symbol BTCUSDT/ETHUSDT 独立
 *  14.   MapService::query topN 截断
 *  15.   MapService distance_pct 正负号（密集区在现价上方=正，下方=负）
 *
 * 全程使用 poc-verify-* 标记的 symbol，结束自动清理 Redis key。
 */
class PocVerifyLiquidationCommand extends Command
{
    protected $signature = 'poc:verify-liquidation';

    protected $description = 'POC: offline end-to-end assertions for P5 liquidation cluster + map service';

    private int $pass = 0;

    private int $fail = 0;

    /** @var string[] */
    private array $failures = [];

    private LiquidationClusterer $clusterer;

    private LiquidationMapService $mapService;

    private LiquidationBucketPolicy $policy;

    /** 测试用 symbol，POC 结束清理 */
    private const TEST_SYMBOL_BTC = 'POCVERIFYBTCUSDT';

    private const TEST_SYMBOL_ETH = 'POCVERIFYETHUSDT';

    public function handle(): int
    {
        $this->info('==== P5 大户爆仓聚类 POC 验证 ====');

        $this->clusterer = new LiquidationClusterer();
        $this->policy = new LiquidationBucketPolicy();
        $this->mapService = new LiquidationMapService($this->clusterer, $this->policy);

        try {
            $this->cleanupFixtures();

            $this->checkDtoParsing();              // 1
            $this->checkDtoValueUsd();              // 2
            $this->checkBucketPolicyBoundaries();  // 3
            $this->checkIngestSingle();            // 4
            $this->checkIngestBatch();             // 5
            $this->checkSnapshotStructure();       // 6
            $this->checkSnapshotSorting();         // 7
            $this->checkBucketQtyConsistency();    // 8
            $this->checkEmptyBucketCleanup();      // 9
            $this->checkEvictionReducesZcard();    // 10
            $this->checkWindowIsolation();         // 11
            $this->checkSideIsolation();           // 12
            $this->checkSymbolIsolation();         // 13
            $this->checkMapServiceTopN();          // 14
            $this->checkDistancePctSign();         // 15
        } catch (Throwable $e) {
            $this->fail++;
            $this->failures[] = '验证流程异常中断：' . $e->getMessage();
            $this->error('FATAL: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
        } finally {
            $this->cleanupFixtures();
        }

        $this->newLine();
        $this->info(sprintf('RESULT: %d/%d passed', $this->pass, $this->pass + $this->fail));
        if ($this->fail > 0) {
            $this->error('VERIFY FAILED');
            foreach ($this->failures as $f) {
                $this->line('  - ' . $f);
            }

            return self::FAILURE;
        }

        $this->info('ALL CHECKS PASSED');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // 断言助手
    // ------------------------------------------------------------------

    private function check(bool $cond, string $label, string $detail = ''): void
    {
        if ($cond) {
            $this->pass++;
            $this->line('  PASS ' . $label);
        } else {
            $this->fail++;
            $this->failures[] = $label . ($detail !== '' ? "（{$detail}）" : '');
            $this->error('  FAIL ' . $label . ($detail !== '' ? "（{$detail}）" : ''));
        }
    }

    // ------------------------------------------------------------------
    // 1-2. DTO 解析与 value_usd 计算
    // ------------------------------------------------------------------

    private function checkDtoParsing(): void
    {
        $payload = [
            's' => 'BTCUSDT',
            'S' => 'SELL',
            'ap' => '60000.50',
            'z' => '0.5',
            'T' => 1700000000000,
        ];
        $e = LiquidationEvent::fromBinancePayload($payload, 1700000000500);

        $ok = $e->symbol === 'BTCUSDT'
            && $e->side === 'SELL'
            && $e->price === '60000.50'
            && $e->qty === '0.5'
            && $e->tsMs === 1700000000000
            && $e->exchangeCode === 'binance';
        $this->check($ok, 'DTO 解析 o.s/o.S/o.ap/o.z/o.T', "symbol={$e->symbol} side={$e->side} price={$e->price} qty={$e->qty} tsMs={$e->tsMs}");
    }

    private function checkDtoValueUsd(): void
    {
        $e = LiquidationEvent::fromBinancePayload([
            's' => 'BTCUSDT', 'S' => 'SELL', 'ap' => '60000.50', 'z' => '0.5', 'T' => 1700000000000,
        ]);
        $expected = bcmul('60000.50', '0.5', 8); // 30000.25000000
        $this->check($e->valueUsd === $expected, 'DTO value_usd = price × qty', "expected {$expected}, got {$e->valueUsd}");
    }

    // ------------------------------------------------------------------
    // 3. 桶宽策略边界
    // ------------------------------------------------------------------

    private function checkBucketPolicyBoundaries(): void
    {
        // POC 测试 symbol 配置（POCVERIFYBTCUSDT 不在 config，走 default=1；我们临时设 bw=60）
        $bw = '60';
        $i1 = $this->policy->bucketIndex('60000', $bw); // 1000
        $i2 = $this->policy->bucketIndex('60059', $bw); // 1000.98 → 1000
        $i3 = $this->policy->bucketIndex('60060', $bw); // 1001

        $this->check(
            $i1 === $i2 && $i1 !== $i3,
            '桶宽 0.1%（bw=60）边界：60000/60059 同桶、60060 异桶',
            "i1={$i1} i2={$i2} i3={$i3}",
        );
    }

    // ------------------------------------------------------------------
    // 4-5. ingest 写入
    // ------------------------------------------------------------------

    private function checkIngestSingle(): void
    {
        // 独立 BUY side 测试，避免和后续 SELL 批量测试串扰
        $this->clusterer->clearWindow('binance', self::TEST_SYMBOL_BTC, 'BUY', '5m');
        $e = $this->makeEvent(self::TEST_SYMBOL_BTC, 'BUY', '60000', '0.5');
        $this->clusterer->ingest($e, '5m', eid: 'poc-eid-1');
        $zsetKey = $this->zsetKey('binance', self::TEST_SYMBOL_BTC, 'BUY', '5m');
        $card = Redis::connection('default')->zCard($zsetKey);

        $this->check((int) $card === 1, 'ingest 单条后 ZCARD=1', "got {$card}");
    }

    private function checkIngestBatch(): void
    {
        // SELL side 独立 clear + 批量写 1000 条同桶
        $this->clusterer->clearWindow('binance', self::TEST_SYMBOL_BTC, 'SELL', '5m');
        $baseTs = (int) (microtime(true) * 1000); // 用当前时间，确保不被 eviction 清掉
        for ($i = 0; $i < 1000; $i++) {
            $e = $this->makeEvent(
                self::TEST_SYMBOL_BTC,
                'SELL',
                '60000', // 同一桶 bw=60 → bucketIndex=1000
                '0.001',
                $baseTs + $i,
            );
            $this->clusterer->ingest($e, '5m', eid: 'poc-eid-batch-' . $i);
        }
        $zsetKey = $this->zsetKey('binance', self::TEST_SYMBOL_BTC, 'SELL', '5m');
        $card = Redis::connection('default')->zCard($zsetKey);

        $this->check((int) $card === 1000, 'ingest 批量 1000 后 ZCARD=1000', "got {$card}");
    }

    // ------------------------------------------------------------------
    // 6-7. snapshot 结构与排序
    // ------------------------------------------------------------------

    private function checkSnapshotStructure(): void
    {
        $snap = $this->clusterer->snapshot('binance', self::TEST_SYMBOL_BTC, 'SELL', '5m');
        $first = $snap[0] ?? null;
        $ok = is_array($first)
            && isset($first['bucket_index'], $first['center'], $first['from'], $first['to'], $first['total_qty'], $first['event_count']);
        $this->check($ok, 'snapshot 返回结构含 6 字段', $first === null ? 'empty' : json_encode(array_keys($first)));
    }

    private function checkSnapshotSorting(): void
    {
        $snap = $this->clusterer->snapshot('binance', self::TEST_SYMBOL_BTC, 'SELL', '5m');
        $ok = true;
        for ($i = 1, $n = count($snap); $i < $n; $i++) {
            if (bccomp($snap[$i - 1]['total_qty'], $snap[$i]['total_qty'], 8) < 0) {
                $ok = false;
                break;
            }
        }
        $this->check($ok, 'snapshot 按 total_qty desc 排序');
    }

    // ------------------------------------------------------------------
    // 8. HASH bucket qty 一致性
    // ------------------------------------------------------------------

    private function checkBucketQtyConsistency(): void
    {
        // 独立 clear + 写 1000 条同桶（qty=0.001 each），累计应为 1.000
        $this->clusterer->clearWindow('binance', self::TEST_SYMBOL_BTC, 'SELL', '5m');
        $baseTs = (int) (microtime(true) * 1000);
        for ($i = 0; $i < 1000; $i++) {
            $e = $this->makeEvent(self::TEST_SYMBOL_BTC, 'SELL', '60000', '0.001', $baseTs + $i);
            $this->clusterer->ingest($e, '5m', eid: 'poc-eid-consist-' . $i);
        }
        $snap = $this->clusterer->snapshot('binance', self::TEST_SYMBOL_BTC, 'SELL', '5m');
        $expected = '1'; // 1000 × 0.001 = 1（HINCRBYFLOAT 累加）
        $bucket1000 = null;
        foreach ($snap as $b) {
            if ($b['bucket_index'] === '1000') {
                $bucket1000 = $b;
                break;
            }
        }
        $qtyOk = $bucket1000 !== null && bccomp($bucket1000['total_qty'], $expected, 6) === 0;
        $countOk = $bucket1000 !== null && $bucket1000['event_count'] === 1000;
        $this->check($qtyOk && $countOk, 'HASH bucket qty 与 SUM(qty) 一致', $bucket1000 === null ? 'no bucket 1000' : "qty={$bucket1000['total_qty']} count={$bucket1000['event_count']}");
    }

    // ------------------------------------------------------------------
    // 9-10. eviction 清理
    // ------------------------------------------------------------------

    private function checkEmptyBucketCleanup(): void
    {
        // 独立 BUY/5m 测试，先 clear 避免前序残留
        $this->clusterer->clearWindow('binance', self::TEST_SYMBOL_BTC, 'BUY', '5m');
        // 写 1 条到 bucketIndex 1000，老时间戳确保过期
        $e = $this->makeEvent(self::TEST_SYMBOL_BTC, 'BUY', '60000', '0.5', 1700000000000);
        $this->clusterer->ingest($e, '5m', eid: 'poc-eid-evict-1');

        // 调 evictExpired：cutoff = now - 300s，1700000000000 必然 < cutoff（1970 起）
        $cleaned = $this->clusterer->evictExpired('binance', self::TEST_SYMBOL_BTC, 'BUY', '5m');

        $bucketKey = $this->bucketHashKey('binance', self::TEST_SYMBOL_BTC, 'BUY', '5m');
        $remain = Redis::connection('default')->hGetAll($bucketKey);

        $this->check($cleaned === 1 && $remain === [], 'evictExpired 后空桶 HDEL 自动清理', "cleaned={$cleaned} remain=" . json_encode($remain));
    }

    private function checkEvictionReducesZcard(): void
    {
        // 同上：写 N 条 ts 很老的事件，evict 后 ZCARD 应减为 0
        $baseTs = 1700000000000;
        for ($i = 0; $i < 5; $i++) {
            $e = $this->makeEvent(self::TEST_SYMBOL_BTC, 'BUY', '60000', '0.001', $baseTs + $i);
            $this->clusterer->ingest($e, '15m', eid: 'poc-eid-evict-reduce-' . $i);
        }
        $zsetKey = $this->zsetKey('binance', self::TEST_SYMBOL_BTC, 'BUY', '15m');
        $cardBefore = (int) Redis::connection('default')->zCard($zsetKey);

        $cleaned = $this->clusterer->evictExpired('binance', self::TEST_SYMBOL_BTC, 'BUY', '15m');
        $cardAfter = (int) Redis::connection('default')->zCard($zsetKey);

        $this->check($cardBefore === 5 && $cleaned === 5 && $cardAfter === 0, 'evictExpired 后 ZCARD 减少到 0', "before={$cardBefore} cleaned={$cleaned} after={$cardAfter}");
    }

    // ------------------------------------------------------------------
    // 11-13. 隔离性
    // ------------------------------------------------------------------

    private function checkWindowIsolation(): void
    {
        // 同 symbol 同 side，不同 window 不串扰
        $this->clusterer->clearWindow('binance', self::TEST_SYMBOL_ETH, 'SELL', '5m');
        $this->clusterer->clearWindow('binance', self::TEST_SYMBOL_ETH, 'SELL', '15m');
        $this->clusterer->clearWindow('binance', self::TEST_SYMBOL_ETH, 'SELL', '60m');

        $e5 = $this->makeEvent(self::TEST_SYMBOL_ETH, 'SELL', '3000', '1.0');
        $this->clusterer->ingest($e5, '5m', eid: 'poc-wiso-5m');
        $e15 = $this->makeEvent(self::TEST_SYMBOL_ETH, 'SELL', '3000', '2.0');
        $this->clusterer->ingest($e15, '15m', eid: 'poc-wiso-15m');
        $e60 = $this->makeEvent(self::TEST_SYMBOL_ETH, 'SELL', '3000', '4.0');
        $this->clusterer->ingest($e60, '60m', eid: 'poc-wiso-60m');

        $c5 = $this->clusterer->snapshot('binance', self::TEST_SYMBOL_ETH, 'SELL', '5m');
        $c15 = $this->clusterer->snapshot('binance', self::TEST_SYMBOL_ETH, 'SELL', '15m');
        $c60 = $this->clusterer->snapshot('binance', self::TEST_SYMBOL_ETH, 'SELL', '60m');

        $ok = count($c5) === 1 && $c5[0]['total_qty'] === '1'
            && count($c15) === 1 && $c15[0]['total_qty'] === '2'
            && count($c60) === 1 && $c60[0]['total_qty'] === '4';

        $this->check($ok, 'window 5m/15m/60m 三档独立 ZSET 不串扰', "5m=" . count($c5) . " 15m=" . count($c15) . " 60m=" . count($c60));
    }

    private function checkSideIsolation(): void
    {
        // 同 symbol 同 window，不同 side 不混淆
        $this->clusterer->clearWindow('binance', self::TEST_SYMBOL_BTC, 'BUY', '5m');
        $this->clusterer->clearWindow('binance', self::TEST_SYMBOL_BTC, 'SELL', '5m');

        $eBuy = $this->makeEvent(self::TEST_SYMBOL_BTC, 'BUY', '60000', '1.0');
        $this->clusterer->ingest($eBuy, '5m', eid: 'poc-siso-buy');
        $eSell = $this->makeEvent(self::TEST_SYMBOL_BTC, 'SELL', '60000', '2.0');
        $this->clusterer->ingest($eSell, '5m', eid: 'poc-siso-sell');

        $cBuy = $this->clusterer->snapshot('binance', self::TEST_SYMBOL_BTC, 'BUY', '5m');
        $cSell = $this->clusterer->snapshot('binance', self::TEST_SYMBOL_BTC, 'SELL', '5m');

        $ok = count($cBuy) === 1 && $cBuy[0]['total_qty'] === '1'
            && count($cSell) === 1 && $cSell[0]['total_qty'] === '2';

        $this->check($ok, '同 symbol 不同 side (BUY/SELL) 独立', "buy=" . json_encode($cBuy) . " sell=" . json_encode($cSell));
    }

    private function checkSymbolIsolation(): void
    {
        // 不同 symbol 不混淆（在 TEST_SYMBOL_BTC/ETH 上各写一条）
        $this->clusterer->clearWindow('binance', self::TEST_SYMBOL_BTC, 'SELL', '5m');
        $this->clusterer->clearWindow('binance', self::TEST_SYMBOL_ETH, 'SELL', '5m');

        $eBtc = $this->makeEvent(self::TEST_SYMBOL_BTC, 'SELL', '60000', '1.0');
        $this->clusterer->ingest($eBtc, '5m', eid: 'poc-symbtc-btc');
        $eEth = $this->makeEvent(self::TEST_SYMBOL_ETH, 'SELL', '3000', '10.0');
        $this->clusterer->ingest($eEth, '5m', eid: 'poc-symbtc-eth');

        $cBtc = $this->clusterer->snapshot('binance', self::TEST_SYMBOL_BTC, 'SELL', '5m');
        $cEth = $this->clusterer->snapshot('binance', self::TEST_SYMBOL_ETH, 'SELL', '5m');

        $ok = count($cBtc) === 1 && bccomp($cBtc[0]['total_qty'], '1.0', 8) === 0
            && count($cEth) === 1 && bccomp($cEth[0]['total_qty'], '10.0', 8) === 0;

        $this->check($ok, '跨 symbol BTCUSDT/ETHUSDT 独立', "btc=" . count($cBtc) . " eth=" . count($cEth));
    }

    // ------------------------------------------------------------------
    // 14-15. MapService
    // ------------------------------------------------------------------

    private function checkMapServiceTopN(): void
    {
        // 构造 15 个不同 bucket（用不同价格，bw=60 时每 60 USDT 一桶）
        $this->clusterer->clearWindow('binance', self::TEST_SYMBOL_BTC, 'SELL', '5m');
        for ($i = 0; $i < 15; $i++) {
            // 价格 60000 + i*100，每桶 60 USDT 步长，落 bucketIndex 1000+i*?
            $price = bcadd('60000', bcmul((string) $i, '100', 8), 8); // 60000, 60100, 60200...
            $e = $this->makeEvent(self::TEST_SYMBOL_BTC, 'SELL', $price, '0.001');
            $this->clusterer->ingest($e, '5m', eid: 'poc-topn-' . $i);
        }

        $rows = $this->mapService->query('binance', self::TEST_SYMBOL_BTC, '5m', topN: 10, currentPrice: null);
        $this->check(count($rows) === 10, 'MapService::query topN=10 截断', 'got ' . count($rows) . ' rows');
    }

    private function checkDistancePctSign(): void
    {
        // 现价 60000，bucketCenter 60000（同位）→ distance 0
        // 现价 60000，bucketCenter 60600（上方）→ distance +1.0%
        // 现价 60000，bucketCenter 59400（下方）→ distance -1.0%
        $this->clusterer->clearWindow('binance', self::TEST_SYMBOL_BTC, 'SELL', '5m');

        $e1 = $this->makeEvent(self::TEST_SYMBOL_BTC, 'SELL', '60600', '1.0'); // 桶 60600
        $this->clusterer->ingest($e1, '5m', eid: 'poc-dist-1');
        $e2 = $this->makeEvent(self::TEST_SYMBOL_BTC, 'SELL', '59400', '1.0'); // 桶 59400
        $this->clusterer->ingest($e2, '5m', eid: 'poc-dist-2');

        $rows = $this->mapService->query('binance', self::TEST_SYMBOL_BTC, '5m', topN: 10, currentPrice: '60000');

        $upRow = null;
        $downRow = null;
        foreach ($rows as $r) {
            if (bccomp($r['bucket_center'], '60600', 8) === 0) {
                $upRow = $r;
            } elseif (bccomp($r['bucket_center'], '59400', 8) === 0) {
                $downRow = $r;
            }
        }

        $ok = $upRow !== null
            && bccomp($upRow['distance_pct'], '0', 4) === 1  // 正
            && $downRow !== null
            && bccomp($downRow['distance_pct'], '0', 4) === -1; // 负

        $this->check($ok, 'distance_pct 正负号（上方=正，下方=负）', "up=" . ($upRow['distance_pct'] ?? 'null') . " down=" . ($downRow['distance_pct'] ?? 'null'));
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function makeEvent(string $symbol, string $side, string $price, string $qty, ?int $tsMs = null): LiquidationEvent
    {
        return new LiquidationEvent(
            exchangeCode: 'binance',
            symbol: $symbol,
            side: $side,
            price: $price,
            qty: $qty,
            valueUsd: bcmul($price, $qty, 8),
            tsMs: $tsMs ?? (int) (microtime(true) * 1000),
            exchangeEventId: null,
        );
    }

    private function zsetKey(string $ex, string $sym, string $side, string $window): string
    {
        $p = (string) config('liquidation.redis_prefix', 'liq');

        return "{$p}:z:{$ex}:{$sym}:{$side}:{$window}";
    }

    private function bucketHashKey(string $ex, string $sym, string $side, string $window): string
    {
        $p = (string) config('liquidation.redis_prefix', 'liq');

        return "{$p}:b:{$ex}:{$sym}:{$side}:{$window}";
    }

    private function cleanupFixtures(): void
    {
        // 清空所有测试 symbol 在所有窗口和 side 上的 key
        $symbols = [self::TEST_SYMBOL_BTC, self::TEST_SYMBOL_ETH];
        $sides = ['BUY', 'SELL'];
        $windows = ['5m', '15m', '60m'];
        $redis = Redis::connection('default');

        foreach ($symbols as $sym) {
            foreach ($sides as $side) {
                foreach ($windows as $w) {
                    $zsetKey = $this->zsetKey('binance', $sym, $side, $w);
                    $eids = $redis->zRange($zsetKey, 0, -1);
                    if (is_array($eids)) {
                        foreach ($eids as $eid) {
                            $redis->del($this->eidKeyStr($eid));
                        }
                    }
                    $redis->del([
                        $zsetKey,
                        $this->bucketHashKey('binance', $sym, $side, $w),
                        $this->countHashKey('binance', $sym, $side, $w),
                    ]);
                }
            }
        }
    }

    private function eidKeyStr(string $eid): string
    {
        $p = (string) config('liquidation.redis_prefix', 'liq');

        return "{$p}:eid:{$eid}";
    }

    private function countHashKey(string $ex, string $sym, string $side, string $window): string
    {
        $p = (string) config('liquidation.redis_prefix', 'liq');

        return "{$p}:bc:{$ex}:{$sym}:{$side}:{$window}";
    }
}
