<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\ExchangeManager;
use App\Domain\Signal\BarAggregator;
use App\Domain\Signal\Contracts\AiUnavailableException;
use App\Domain\Signal\Contracts\Signal;
use App\Domain\Signal\IndicatorCalculator;
use App\Domain\Signal\Prompters\DeepSeekAdapter;
use App\Domain\Signal\RuleEngineFallback;
use App\Domain\Signal\SignalDispatcher;
use App\Domain\Signal\SignalPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use ReflectionMethod;
use Throwable;

/**
 * P2 POC 真实服务 smoke（离线断言主体已于 P5.5 步骤 3 迁入 PHPUnit：
 * tests/Unit/Signal/IndicatorCalculatorTest、tests/Integration/Signal/
 * BarAggregatorTest/DeepSeekAdapterTest/SignalPipelineAiTest/
 * PriceDeviationGuardTest/SignalDispatcherDedupTest；逐条映射见
 * docs/p5.5-hardening-plan.md「步骤 3 迁移映射附录」）。
 *
 * 本命令保留的 smoke 价值（CI 不覆盖，需在可访问 Binance/DeepSeek 的环境手工执行）：
 *  - [2/7] 交易所 REST 真实拉取 120 根 5m bar
 *  - [3/7] 真实 DEEPSEEK_API_KEY 全链路一轮
 * 其余 [1]/[4]/[5]/[6] 段为离线断言，正式回归以 php artisan test 为准。
 *
 * 历史 7 段（P2 POC 离线全链路验证）：
 *  1. BarAggregator 合成 1m→5m 聚合 + OHLCV/trades + Redis 崩溃恢复 + 1m 直通
 *  2. IndicatorCalculator 真实 120 根 5m bar：指标齐全且数值合理
 *  3. 真实 DeepSeek 调用：合法 Signal DTO；方向性信号必须落 signals(source=ai)
 *  4. 错误 key → AiUnavailableException → RuleEngineFallback 全链路落库(source=rule_fallback)
 *  5. 价差守卫：偏离 >1% 丢弃、≤1% 放行
 *  6. 通知去重：同指纹第二次分发落 deduped
 *  7. signals / signal_notifications 汇总表
 *
 * 前置：tenant#1 存在（P1 poc:testnet-setup 已建）、DEEPSEEK_API_KEY 已配置。
 */
class PocVerifyAiCommand extends Command
{
    protected $signature = 'poc:verify-ai';

    protected $description = 'POC: verify end-to-end AI signal chain (aggregation/indicators/DeepSeek/fallback/guard/dedup)';

    private const SUB_NAME = 'poc-verify-ai';

    private int $passed = 0;

    private int $failed = 0;

    public function handle(): int
    {
        $tenant = DB::table('tenants')->where('id', 1)->first();
        if ($tenant === null) {
            $this->error('tenant#1 不存在，请先跑 php artisan poc:testnet-setup');

            return self::FAILURE;
        }

        $subscription = $this->ensureSubscription();

        $this->newLine();
        $this->line('===== [1/7] BarAggregator 合成数据 =====');
        $this->checkAggregator();

        $this->newLine();
        $this->line('===== [2/7] IndicatorCalculator 真实 REST 数据 =====');
        $realBars = $this->checkIndicators();

        $this->newLine();
        $this->line('===== [3/7] 真实 DeepSeek 调用 =====');
        if ($realBars !== null) {
            $this->checkRealAi($subscription, $realBars);
        } else {
            $this->record(false, 'REST 数据不可用，跳过（上一项已失败）');
        }

        $this->newLine();
        $this->line('===== [4/7] 错误 key → 规则引擎兜底落库 =====');
        $this->checkFallback($subscription);

        $this->newLine();
        $this->line('===== [5/7] 价差守卫 =====');
        $this->checkPriceGuard();

        $this->newLine();
        $this->line('===== [6/7] 通知冷却去重 =====');
        $this->checkDedup();

        $this->newLine();
        $this->line('===== [7/7] 落库汇总 =====');
        $this->printSummary();

        $this->newLine();
        $total = $this->passed + $this->failed;
        $this->info("RESULT: {$this->passed}/{$total} passed");
        if ($this->failed > 0) {
            $this->error('VERIFY FAILED');

            return self::FAILURE;
        }

        $this->info('ALL CHECKS PASSED');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // 1. BarAggregator
    // ------------------------------------------------------------------

    private function checkAggregator(): void
    {
        $redis = Redis::connection('default');
        $redis->del('market:agg:binance:POCTEST:5m');

        try {
            $base = intdiv(time() * 1000, 300_000) * 300_000;
            $agg = new BarAggregator;

            // 1m 直通
            $first = $this->synthetic1mBar($base, 0);
            $passthrough = $agg->aggregate($first, '1m');
            $this->assert($passthrough === $first, 'interval=1m 直通返回原 bar');

            // 同桶 5 根（i=0..4），第 6 根（i=5）跨入下一 5m 桶 → 返回闭合 bar
            $closed = null;
            for ($i = 0; $i <= 5; $i++) {
                $closed = $agg->aggregate($this->synthetic1mBar($base + $i * 60_000, $i), '5m') ?? $closed;
            }

            $this->assert($closed !== null, '第 6 根跨桶时产出闭合 5m bar');
            if ($closed !== null) {
                $this->assert($closed->openTime === $base, 'openTime 对齐 5m 桶起点');
                $this->assert($closed->closeTime === $base + 299_999, 'closeTime 为桶末');
                $this->assert($closed->open === '100', '聚合 open 取桶内首根 open（100）');
                $this->assert($closed->high === '118', '聚合 high 取最大值（118）');
                $this->assert($closed->low === '86', '聚合 low 取最小值（86）');
                $this->assert($closed->close === '104', '聚合 close 取末根 close（104）');
                $this->assert(bccomp($closed->volume, '7.5', 8) === 0, '聚合 volume 累加（7.5）');
                $this->assert(bccomp($closed->quoteVolume, '7500', 8) === 0, '聚合 quoteVolume 累加（7500）');
                $this->assert($closed->tradesCount === 15, '聚合 tradesCount 累加（15）');
                $this->assert($agg->lastClosedTicks('binance', 'POCTEST', '5m') === 5, '完整桶 ticks=5');
            }

            // 崩溃恢复：新实例从 Redis hydrate 未闭桶；再喂同桶 1 根 → null，跨桶 → 闭合
            $agg2 = new BarAggregator;
            $sameBucket = $agg2->aggregate($this->synthetic1mBar($base + 360_000, 6), '5m');
            $this->assert($sameBucket === null, 'hydrate 后喂入同桶 bar 返回 null');
            $closed2 = $agg2->aggregate($this->synthetic1mBar($base + 600_000, 7), '5m');
            $this->assert($closed2 !== null, 'hydrate 后跨桶能闭合（崩溃恢复成功）');
            if ($closed2 !== null) {
                $this->assert($closed2->open === '100' && $closed2->close === '106', '恢复桶 OHLC 正确（open=100 close=106）');
                $this->assert($closed2->tradesCount === 6 && bccomp($closed2->volume, '3.0', 8) === 0, '恢复桶量累加正确（vol=3.0 trades=6）');
                $this->assert($agg2->lastClosedTicks('binance', 'POCTEST', '5m') === 2, '恢复桶 ticks=2（仅收到 2 根 1m，可识别为残次桶）');
            }
        } catch (Throwable $e) {
            $this->record(false, '异常：'.$e->getMessage());
        } finally {
            $redis->del('market:agg:binance:POCTEST:5m');
        }
    }

    private function synthetic1mBar(int $openTime, int $i): ExchangeKline
    {
        return new ExchangeKline(
            exchangeCode: 'binance',
            symbol: 'POCTEST',
            interval: '1m',
            openTime: $openTime,
            closeTime: $openTime + 59_999,
            open: '100',
            high: (string) (110 + 2 * $i),
            low: (string) (90 - $i),
            close: (string) (100 + $i),
            volume: '1.5',
            quoteVolume: '1500',
            tradesCount: 3,
        );
    }

    // ------------------------------------------------------------------
    // 2. IndicatorCalculator
    // ------------------------------------------------------------------

    /**
     * @return ExchangeKline[]|null
     */
    private function checkIndicators(): ?array
    {
        try {
            $adapter = app(ExchangeManager::class)->adapter('binance', 'spot', []);
            $bars = $adapter->getKline('BTCUSDT', '5m', 120);
            $this->assert(count($bars) >= 100, sprintf('REST 拉到 %d 根 5m bar（≥100）', count($bars)));

            $ind = app(IndicatorCalculator::class)->compute($bars);
            $this->line('      '.json_encode($ind, JSON_UNESCAPED_UNICODE));

            $this->assert(
                is_numeric($ind['ma'][7]['v']) && is_numeric($ind['ma'][25]['v']) && is_numeric($ind['ma'][99]['v']),
                'MA7/25/99 均有值',
            );
            $this->assert(is_numeric($ind['rsi']) && $ind['rsi'] >= 0 && $ind['rsi'] <= 100, 'RSI ∈ [0,100]');
            $this->assert(
                $ind['boll']['upper'] >= $ind['boll']['middle'] && $ind['boll']['middle'] >= $ind['boll']['lower'],
                'BOLL 上轨 ≥ 中轨 ≥ 下轨',
            );
            $this->assert(is_numeric($ind['atr']) && $ind['atr'] > 0, 'ATR > 0');
            $this->assert(
                is_numeric($ind['macd']['dif']) && is_numeric($ind['macd']['hist']),
                'MACD dif/hist 均有值',
            );

            return $bars;
        } catch (Throwable $e) {
            $this->record(false, '异常：'.$e->getMessage());

            return null;
        }
    }

    // ------------------------------------------------------------------
    // 3. 真实 DeepSeek
    // ------------------------------------------------------------------

    /**
     * @param  ExchangeKline[]  $bars
     */
    private function checkRealAi(object $subscription, array $bars): void
    {
        if ((string) config('ai.providers.deepseek.api_key') === '') {
            $this->record(false, 'DEEPSEEK_API_KEY 未配置（.env）；可先跑 poc:signal-setup 做连通探测');

            return;
        }

        try {
            $pipeline = app(SignalPipeline::class);
            $beforeId = (int) DB::table('signals')->max('id');
            $signal = $pipeline->run($subscription, $bars[count($bars) - 1], $bars);

            if ($signal !== null) {
                $this->assert($signal->source === 'ai', '信号 source=ai（实际：'.$signal->source.'）');
                $this->assert(
                    in_array($signal->direction, ['long', 'short'], true),
                    '方向合法：'.$signal->direction,
                );
                $this->assert((float) $signal->entryPrice > 0, 'entry_price 为正：'.$signal->entryPrice);
                $this->assert(
                    $signal->confidence >= 0 && $signal->confidence <= 100,
                    'confidence ∈ [0,100]：'.$signal->confidence,
                );
                $this->assert($signal->rationale !== null && $signal->rationale !== '', 'rationale 非空');
                $row = DB::table('signals')->where('id', '>', $beforeId)->where('source', 'ai')->latest('id')->first();
                $this->assert($row !== null, 'signals 表存在 source=ai 新记录');
                $this->line(sprintf(
                    '      → %s %s entry=%s exit=%s stop=%s conf=%d',
                    $signal->direction, $signal->symbol, $signal->entryPrice,
                    $signal->exitPrice, $signal->stopLoss, $signal->confidence,
                ));
            } else {
                // pipeline 在 neutral 时返回 null（不落库是正确行为）；直接再调一次验证 DTO 合法性
                $this->line('      pipeline 返回 null（neutral 或被守卫丢弃），直接调 prompter 校验 DTO...');
                $neutral = app(DeepSeekAdapter::class)->analyze($bars[count($bars) - 1], [
                    'tenant_id' => 1,
                    'historical_bars' => $bars,
                    'indicators' => app(IndicatorCalculator::class)->compute($bars),
                ]);
                $this->assert($neutral->direction === 'neutral', 'AI 本轮判断 neutral（合法，不落库）');
                $this->assert($neutral->confidence >= 0 && $neutral->confidence <= 100, 'neutral confidence 合法');
            }
        } catch (Throwable $e) {
            $this->record(false, '异常：'.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 4. 错误 key → 兜底
    // ------------------------------------------------------------------

    private function checkFallback(object $subscription): void
    {
        $originalKey = (string) config('ai.providers.deepseek.api_key');

        try {
            // 确定性形态：100 根走平 → 10 根等幅下跌 → 10 根等幅回升，末根金叉 + MACD 红柱
            $bars = $this->deterministicBullishBars();
            $latest = $bars[count($bars) - 1];

            config(['ai.providers.deepseek.api_key' => 'sk-invalid-poc-key']);

            // 4a 直接调用必须抛 AiUnavailableException
            $threw = false;
            try {
                app(DeepSeekAdapter::class)->analyze($latest, [
                    'tenant_id' => 1,
                    'historical_bars' => $bars,
                    'indicators' => app(IndicatorCalculator::class)->compute($bars),
                ]);
            } catch (AiUnavailableException) {
                $threw = true;
            }
            $this->assert($threw, '错误 key 触发 AiUnavailableException');

            // 4b 全链路：AI 失败 → 规则兜底 → 落库
            $beforeId = (int) DB::table('signals')->max('id');
            $signal = app(SignalPipeline::class)->run($subscription, $latest, $bars);

            $this->assert($signal !== null, '兜底管线返回信号');
            if ($signal !== null) {
                $this->assert($signal->source === 'rule_fallback', 'source=rule_fallback');
                $this->assert($signal->direction === 'long', '确定性形态判为 long（实际：'.$signal->direction.'）');
                $this->assert(bccomp($signal->entryPrice, '100', 8) === 0, 'entry=100（形态末收盘价）');
                $this->assert(
                    $signal->exitPrice !== null && bccomp($signal->exitPrice, $signal->entryPrice, 8) > 0,
                    '止盈 > 入场',
                );
                $this->assert(
                    $signal->stopLoss !== null && bccomp($signal->stopLoss, $signal->entryPrice, 8) < 0,
                    '止损 < 入场',
                );
                $row = DB::table('signals')->where('id', '>', $beforeId)->where('source', 'rule_fallback')->latest('id')->first();
                $this->assert($row !== null, 'signals 表存在 source=rule_fallback 新记录');
                $this->line('      '.$signal->rationale);
            }
        } catch (Throwable $e) {
            $this->record(false, '异常：'.$e->getMessage());
        } finally {
            config(['ai.providers.deepseek.api_key' => $originalKey]);
        }
    }

    /**
     * 确定性多头形态（120 根 5m，末根规则引擎 score=3：金叉 + MACD 红柱）。
     *
     * @return ExchangeKline[]
     */
    private function deterministicBullishBars(): array
    {
        $d = 0.2;
        $closes = array_fill(0, 100, 100.0);
        for ($i = 1; $i <= 10; $i++) {
            $closes[] = 100.0 - $i * $d;
        }
        $price = 100.0 - 10 * $d;
        for ($i = 0; $i < 10; $i++) {
            $price += $d;
            $closes[] = $price;
        }

        $base = intdiv(time() * 1000, 300_000) * 300_000 - 120 * 300_000;
        $bars = [];
        foreach ($closes as $i => $c) {
            $bars[] = new ExchangeKline(
                exchangeCode: 'binance',
                symbol: 'PBFTEST',
                interval: '5m',
                openTime: $base + $i * 300_000,
                closeTime: $base + $i * 300_000 + 299_999,
                open: (string) $c,
                high: (string) $c,
                low: (string) $c,
                close: (string) $c,
                volume: '10',
                quoteVolume: (string) ($c * 10),
                tradesCount: 5,
            );
        }

        return $bars;
    }

    // ------------------------------------------------------------------
    // 5. 价差守卫
    // ------------------------------------------------------------------

    private function checkPriceGuard(): void
    {
        try {
            $pipeline = app(SignalPipeline::class);
            $method = new ReflectionMethod($pipeline, 'priceDeviationExceeds');
            $bar = new ExchangeKline(
                'binance', 'BTCUSDT', '5m', 0, 299_999,
                '100', '100', '100', '100', '1', '1', 1,
            );

            $drift = new Signal('BTCUSDT', '5m', 'long', '102', '105', '100.5', 80, 'x', 'ai', null);
            $ok = new Signal('BTCUSDT', '5m', 'long', '100.2', '105', '99.8', 80, 'x', 'ai', null);

            $this->assert($method->invoke($pipeline, $drift, $bar) === true, '偏离 2%（>1%）被丢弃');
            $this->assert($method->invoke($pipeline, $ok, $bar) === false, '偏离 0.2%（≤1%）放行');
        } catch (Throwable $e) {
            $this->record(false, '异常：'.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 6. 去重
    // ------------------------------------------------------------------

    private function checkDedup(): void
    {
        try {
            $row = DB::table('signals')->where('symbol', 'PBFTEST')->where('source', 'rule_fallback')->latest('id')->first();
            if ($row === null) {
                $this->record(false, '兜底信号未落库，无法测去重');

                return;
            }

            $signal = new Signal(
                'PBFTEST', '5m', 'long',
                (string) $row->entry_price, (string) $row->exit_price,
                $row->stop_loss !== null ? (string) $row->stop_loss : null,
                (int) $row->confidence, null, 'rule_fallback', null,
                1, (int) $row->subscription_id, (int) $row->id,
            );

            $fingerprint = sha1('PBFTEST|5m|long');
            Redis::connection('default')->del("tenant:1:dedup:signal:{$fingerprint}");

            // 只统计本次两次分发产生的行（check4 管线自身也分发过一次）
            $beforeNoteId = (int) DB::table('signal_notifications')->max('id');

            $dispatcher = app(SignalDispatcher::class);
            $dispatcher->dispatch($signal, ['log']);
            $dispatcher->dispatch($signal, ['log']);

            $rows = DB::table('signal_notifications')
                ->where('id', '>', $beforeNoteId)
                ->where('signal_id', $row->id)
                ->where('channel', 'log')
                ->orderBy('id')
                ->get();

            $first = $rows[0] ?? null;
            $second = $rows[1] ?? null;

            $this->assert($first !== null && in_array($first->status, ['queued', 'sent'], true), '首次分发状态 queued/sent（实际：'.($first->status ?? '-').'）');
            $this->assert($second !== null && $second->status === 'deduped', '二次分发命中冷却 deduped（实际：'.($second->status ?? '-').'）');
        } catch (Throwable $e) {
            $this->record(false, '异常：'.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 7. 汇总
    // ------------------------------------------------------------------

    private function printSummary(): void
    {
        $this->line('最近 signals：');
        $signals = DB::table('signals')->orderByDesc('id')->limit(10)->get(['id', 'symbol', 'direction', 'source', 'confidence', 'triggered_at']);
        foreach ($signals as $s) {
            $this->line(sprintf(
                '  #%d %s %s source=%s conf=%s @%s',
                $s->id, $s->symbol, $s->direction, $s->source, $s->confidence, $s->triggered_at,
            ));
        }

        $this->newLine();
        $this->line('最近 signal_notifications：');
        $notes = DB::table('signal_notifications')->orderByDesc('id')->limit(10)->get(['id', 'signal_id', 'channel', 'status', 'created_at']);
        foreach ($notes as $n) {
            $this->line(sprintf('  #%d signal=%d channel=%s status=%s', $n->id, $n->signal_id, $n->channel, $n->status));
        }

        $this->record(true, '汇总输出完成');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function ensureSubscription(): object
    {
        $now = Carbon::now();
        $row = DB::table('signal_subscriptions')
            ->where('tenant_id', 1)
            ->where('name', self::SUB_NAME)
            ->first();

        if ($row !== null) {
            // 刻意不置 active：这是离线验证脚手架，不能被 SignalFeedRunner 当正式订阅双发
            DB::table('signal_subscriptions')->where('id', $row->id)->update([
                'symbols' => json_encode(['BTCUSDT']),
                'intervals' => json_encode(['5m']),
                'channels' => json_encode(['log']),
                'updated_at' => $now,
            ]);

            return DB::table('signal_subscriptions')->where('id', $row->id)->first();
        }

        $id = (int) DB::table('signal_subscriptions')->insertGetId([
            'tenant_id' => 1,
            'name' => self::SUB_NAME,
            'symbols' => json_encode(['BTCUSDT']),
            'intervals' => json_encode(['5m']),
            'ai_prompter' => 'deepseek',
            'ai_model' => null,
            'rule_fallback_enabled' => 1,
            'channels' => json_encode(['log']),
            'active' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('signal_subscriptions')->where('id', $id)->first();
    }

    private function assert(bool $condition, string $label): void
    {
        $this->record($condition, $label);
    }

    private function record(bool $ok, string $label): void
    {
        if ($ok) {
            $this->passed++;
            $this->line('  <fg=green>PASS</> '.$label);
        } else {
            $this->failed++;
            $this->line('  <fg=red>FAIL</> '.$label);
        }
    }
}
