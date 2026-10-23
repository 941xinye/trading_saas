<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Exchange\ExchangeManager;
use App\Domain\Signal\IndicatorCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 多周期 AI 行情研判（真实行情 + DeepSeek 综合分析，终端报告输出）。
 *
 * 用法：
 *   php artisan ai:multitf-analyze BTCUSDT              # 默认 5m/15m/1h/4h 四周期
 *   php artisan ai:multitf-analyze BTCUSDT --tf=5m,1h   # 指定周期
 *   php artisan ai:multitf-analyze BTCUSDT --provider=qwen
 *   php artisan ai:multitf-analyze BTCUSDT --json       # 原始 JSON 输出（便于脚本消费）
 *
 * 设计：
 *   - 交易所走 ExchangeManager 的 BinanceSpotAdapter（与生产一致）
 *   - AI 直连各 Provider 的 /chat/completions（不用 AbstractAIPrompter，
 *     因为后者绑定 tenant + quota，命令行是一次性分析，计量/限流在此处不适用）
 *   - 指标复用 IndicatorCalculator（纯函数，无 IO）
 *   - 不写 DB、不落队列，纯 read-only 探测
 *   - 失败时 graceful 输出错误信息，exit code = FAILURE
 */
class MultiTfAnalyzeCommand extends Command
{
    protected $signature = 'ai:multitf-analyze
        {symbol : 交易对，如 BTCUSDT}
        {--exchange=binance : 交易所 (binance|okx|bybit|bitget)}
        {--market=spot : 市场类型 (spot|futures)}
        {--tf= : 时间周期列表，逗号分隔，默认 5m,15m,1h,4h}
        {--provider= : AI provider，默认 config(ai.default)}
        {--closed-only : 丢弃最后一根进行中的 bar，只用已闭合 bar 算指标（更保守，数据时点滞后）}
        {--json : 只输出原始 JSON（无格式化报告）}';

    protected $description = 'Multi-timeframe AI market analysis — pull real klines, compute indicators, call LLM, print structured trade recommendation';

    /** 默认周期 + 权重 + bar 拉取数（bar 数需满足 MA99 等长周期预热） */
    private const DEFAULT_TFS = [
        ['interval' => '5m',  'limit' => 150, 'weight' => 1, 'label' => '5分钟（短线择时）'],
        ['interval' => '15m', 'limit' => 150, 'weight' => 2, 'label' => '15分钟（日内波段）'],
        ['interval' => '1h',  'limit' => 200, 'weight' => 3, 'label' => '1小时（日内趋势）'],
        ['interval' => '4h',  'limit' => 200, 'weight' => 4, 'label' => '4小时（中期结构）'],
    ];

    public function handle(): int
    {
        $symbol = strtoupper(trim((string) $this->argument('symbol')));
        $exchange = (string) $this->option('exchange');
        $market = (string) $this->option('market');
        $provider = $this->option('provider') ?? config('ai.default', 'deepseek');
        $rawOnly = (bool) $this->option('json');
        $closedOnly = (bool) $this->option('closed-only');
        $nowMs = (int) round(microtime(true) * 1000);

        $timeframes = $this->resolveTimeframes();
        if ($timeframes === []) {
            $this->error('--tf 格式不正确，应为逗号分隔的周期字符串，如 5m,1h');
            return self::FAILURE;
        }

        if (! is_array(config("ai.providers.{$provider}"))) {
            $this->error("Provider '{$provider}' 未在 config/ai.php 中配置");
            return self::FAILURE;
        }

        if (! $rawOnly) {
            $this->info("=== 多周期 AI 行情研判 ===");
            $this->line("交易对: {$symbol}");
            $this->line("交易所: {$exchange} {$market}");
            $this->line("时间:  " . now('UTC')->format('Y-m-d H:i:s') . " UTC");
            $this->line("AI:    {$provider}");
            if ($closedOnly) {
                $this->line('模式:  closed-only（丢弃进行中 bar，指标基于闭合收盘价，可能滞后一周期）');
            }
            $this->newLine();
        }

        // ---- 1. 拉真实行情 ----
        $adapter = app(ExchangeManager::class)->adapter($exchange, $market, []);
        $indicator = app(IndicatorCalculator::class);
        $msPerTf = ['1m' => 60_000, '5m' => 300_000, '15m' => 900_000, '1h' => 3_600_000, '4h' => 14_400_000, '1d' => 86_400_000];

        $tfData = [];
        foreach ($timeframes as $tf) {
            if (! $rawOnly) {
                $this->output->write("拉取 {$tf['interval']}... ");
            }
            try {
                $bars = $adapter->getKline($symbol, $tf['interval'], $tf['limit']);
            } catch (Throwable $e) {
                if ($rawOnly) {
                    $this->error(json_encode(['error' => 'kline_fetch_failed', 'tf' => $tf['interval'], 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
                } else {
                    $this->error('失败: ' . $e->getMessage());
                }
                return self::FAILURE;
            }

            if (count($bars) < 30) {
                if ($rawOnly) {
                    $this->error(json_encode(['error' => 'insufficient_bars', 'tf' => $tf['interval'], 'got' => count($bars)], JSON_UNESCAPED_UNICODE));
                } else {
                    $this->error("不足 30 根（实际 " . count($bars) . "）");
                }
                return self::FAILURE;
            }

            // ---- 时效性检测 ----
            $tfMs = $msPerTf[$tf['interval']] ?? 0;
            $latest = $bars[count($bars) - 1];
            $barOpenUtc = gmdate('Y-m-d H:i', intdiv($latest->openTime, 1000));
            $curBucketStart = $tfMs > 0 ? intdiv($nowMs, $tfMs) * $tfMs : 0;
            $inProgress = $tfMs > 0 && $latest->openTime === $curBucketStart; // 这根就在当前桶里 → 还在变

            // closed-only：丢最后一根进行中的 bar（指标基于已闭合收盘）
            if ($closedOnly && $inProgress && count($bars) > 30) {
                $bars = array_slice($bars, 0, -1);
                $latest = $bars[count($bars) - 1];
                $inProgress = false; // 倒数第二根肯定是已闭合的
            }

            if (count($bars) < 30) {
                if ($rawOnly) {
                    $this->error(json_encode(['error' => 'insufficient_bars_after_drop', 'tf' => $tf['interval'], 'got' => count($bars)], JSON_UNESCAPED_UNICODE));
                } else {
                    $this->error("closed-only 丢弃后不足 30 根（剩余 " . count($bars) . "）");
                }
                return self::FAILURE;
            }

            $ind = $indicator->compute($bars);
            $tfData[$tf['interval']] = ['tf' => $tf, 'latest' => $latest, 'ind' => $ind];

            if (! $rawOnly) {
                // 时效性标注
                $closeUtc = gmdate('m-d H:i', intdiv($latest->closeTime, 1000));
                if ($inProgress) {
                    $elapsedPct = $tfMs > 0 ? round(($nowMs - $curBucketStart) / $tfMs * 100, 1) : 0;
                    $freshness = sprintf('🔴 进行中 (%s%% 已过)', $elapsedPct);
                } else {
                    $ageSec = round(($nowMs - $latest->closeTime) / 1000);
                    $ageStr = $ageSec < 60 ? $ageSec . 's' : ($ageSec < 3600 ? round($ageSec / 60, 0) . 'm' : round($ageSec / 3600, 1) . 'h');
                    $freshness = '🟢 已闭合 (' . $ageStr . ' 前)';
                }

                $this->line(sprintf(
                    "✓ close=%.2f (%s) RSI=%.1f MACD=%.4g ATR=%.4g | %s",
                    (float) $latest->close, $closeUtc,
                    $ind['rsi'] ?? 0,
                    $ind['macd']['hist'] ?? 0,
                    $ind['atr'] ?? 0,
                    $freshness,
                ));
            }
        }

        // 汇总警告：任何周期是进行中 bar 时提示
        if (! $rawOnly && ! $closedOnly) {
            $progress = [];
            foreach ($tfData as $interval => $d) {
                $tfMs = $msPerTf[$interval] ?? 0;
                if ($tfMs > 0 && $d['latest']->openTime === intdiv($nowMs, $tfMs) * $tfMs) {
                    $elapsedPct = round(($nowMs - intdiv($nowMs, $tfMs) * $tfMs) / $tfMs * 100, 1);
                    $progress[] = "{$interval}({$elapsedPct}%)";
                }
            }
            if ($progress !== []) {
                $this->newLine();
                $this->warn('以下周期最后一根 bar 正在进行中，指标还会变动：' . implode('、', $progress));
                $this->line('  用 --closed-only 丢弃进行中 bar 可让指标基于确定收盘价（但数据时点滞后一周期）');
            }
        }

        // ---- 2. 组装 prompt + 调 LLM ----
        if (! $rawOnly) {
            $this->newLine();
            $this->info("=== 调用 {$provider} 进行多周期综合研判 ===");
        }

        // 收集时效性摘要（传给 AI，让它知道哪些周期的指标还会变）
        $freshnessMap = [];
        foreach ($tfData as $interval => $d) {
            $tfMs = $msPerTf[$interval] ?? 0;
            if ($tfMs > 0 && $d['latest']->openTime === intdiv($nowMs, $tfMs) * $tfMs) {
                $elapsedPct = round(($nowMs - intdiv($nowMs, $tfMs) * $tfMs) / $tfMs * 100, 1);
                $freshnessMap[$interval] = sprintf('进行中（%.1f%% 已过，指标还会变动）', $elapsedPct);
            } else {
                $ageSec = $tfMs > 0 ? round(($nowMs - $d['latest']->closeTime) / 1000) : 0;
                $freshnessMap[$interval] = sprintf('已闭合（%.0fs 前，指标确定）', $ageSec);
            }
        }

        $cfg = (array) config("ai.providers.{$provider}");
        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt = $this->buildUserPrompt($symbol, $tfData, $freshnessMap);

        try {
            $t0 = microtime(true);
            $resp = Http::timeout((int) ($cfg['timeout'] ?? 45))
                ->acceptJson()->asJson()->withToken((string) $cfg['api_key'])
                ->post(rtrim((string) ($cfg['api_base'] ?? ''), '/') . '/chat/completions', [
                    'model' => (string) ($cfg['model'] ?? 'gpt-4o-mini'),
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 1200,
                    'response_format' => ['type' => 'json_object'],
                ]);

            if ($resp->failed()) {
                $msg = sprintf('HTTP %d: %s', $resp->status(), mb_substr((string) $resp->body(), 0, 500));
                if ($rawOnly) {
                    $this->error(json_encode(['error' => 'ai_http_failed', 'msg' => $msg], JSON_UNESCAPED_UNICODE));
                } else {
                    $this->error($msg);
                }
                return self::FAILURE;
            }

            $content = (string) $resp->json('choices.0.message.content', '');
            $elapsed = round(microtime(true) - $t0, 2);
        } catch (Throwable $e) {
            if ($rawOnly) {
                $this->error(json_encode(['error' => 'ai_request_failed', 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('AI 请求失败: ' . $e->getMessage());
            }
            return self::FAILURE;
        }

        // ---- 3. 容错 JSON 解析 ----
        $data = $this->parseJson($content);
        if ($data === null) {
            if ($rawOnly) {
                $this->error(json_encode(['error' => 'json_parse_failed', 'raw' => $content], JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('AI 返回不可解析为 JSON：' . mb_substr($content, 0, 200));
            }
            return self::FAILURE;
        }

        // ---- 4. 输出 ----
        if ($rawOnly) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->printReport($symbol, $data, $tfData, $elapsed);
        return self::SUCCESS;
    }

    /**
     * @return array<int,array{interval:string,limit:int,weight:int,label:string}>
     */
    private function resolveTimeframes(): array
    {
        $opt = $this->option('tf');
        if ($opt === null || $opt === '') {
            return self::DEFAULT_TFS;
        }

        $map = [];
        foreach (self::DEFAULT_TFS as $tf) {
            $map[$tf['interval']] = $tf;
        }

        $picked = [];
        foreach (explode(',', (string) $opt) as $item) {
            $item = trim($item);
            if ($item !== '' && isset($map[$item])) {
                $picked[] = $map[$item];
            }
        }

        return $picked;
    }

    private function buildSystemPrompt(): string
    {
        return <<<'SYS'
你是一名专业的数字货币多周期量化分析师，擅长量价共振、跨周期结构识别与风控。
基于提供的多个时间框架的 K 线与技术指标做综合研判，给出清晰的方向判断与可执行的交易建议。

严格输出一个 JSON 对象（不要任何其他文字或 Markdown），schema 如下：
{
  "summary": "string（80字内整体走势摘要，必须引用具体周期与指标）",
  "multi_tf_resonance": "string（跨周期共振特征描述，≥2个周期同向/矛盾/收敛等）",
  "trend": "long | short | ranging | unclear",
  "conviction": 0-100,
  "plan": {
    "action": "long | short | wait",
    "entry_zone": [low, high],
    "stop_loss": number,
    "take_profit_1": number,
    "take_profit_2": number,
    "position_size_pct": number,
    "time_horizon": "5m|15m|1h|4h",
    "risk_reward_ratio": number,
    "invalidates_when": "string"
  },
  "watchlist": ["string"]
}

规则：
1. 多周期共振优先：≥2个周期同向且指标支撑才给开仓建议；否则 wait。
2. 小周期服从大周期：若高权重周期（如 4h/1h）明确方向，低权重（5m/15m）只做择时辅助。
3. conviction < 50 或 risk_reward_ratio < 1.5 时 action 必须为 wait。
4. entry_zone / stop_loss / take_profit 必须基于给出的实际 K 线价位与 ATR，不能捏造。
5. 必须引用具体指标数值来支撑判断（如 "4h MACD hist=-189.9 空头"、"MA7(98.39)上翘逼近MA25(98.55)" 等）。
6. **时效性规则**：每个周期数据段标有时效属性——"进行中(xx%)" 表示最后一根 bar 还在变动，指标不稳定；"已闭合" 表示指标确定。若 ≥1 个高权重周期（1h/4h）处于进行中，应降低 conviction（-5~-15）并在 invalidates_when 中明确"等待该周期闭合确认方向"。
SYS;
    }

    /**
     * @param array<string,array{tf:array,latest:\App\Domain\Exchange\Contracts\ExchangeKline,ind:array}> $tfData
     * @param array<string,string> $freshnessMap interval => "进行中(xx%) / 已闭合(xxs前)"
     */
    private function buildUserPrompt(string $symbol, array $tfData, array $freshnessMap = []): string
    {
        $parts = ["交易对: {$symbol}（Binance 现货）\n"];

        // 按 weight 降序（大周期放前面，更重要）
        uasort($tfData, static fn (array $a, array $b) => $b['tf']['weight'] <=> $a['tf']['weight']);

        foreach ($tfData as $interval => $data) {
            $tf = $data['tf'];
            $latest = $data['latest'];
            $ind = $data['ind'];
            $freshness = $freshnessMap[$interval] ?? '(未知时效)';

            $parts[] = sprintf(
                "=== %s（%s，权重 %d，时效: %s）===\n最新收盘: %.2f @ %s UTC\n指标：\n%s",
                $tf['label'], $interval, $tf['weight'], $freshness,
                (float) $latest->close,
                gmdate('Y-m-d H:i', intdiv($latest->closeTime, 1000)),
                json_encode($ind, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        }

        // 全局提示
        $progress = [];
        foreach ($freshnessMap as $interval => $info) {
            if (str_starts_with($info, '进行中')) {
                $progress[] = "{$interval} {$info}";
            }
        }
        if ($progress !== []) {
            $parts[] = "⚠️ 注意：以下周期最后一根 bar 正在进行中，指标还会继续变动，研判时请考虑这种不确定性：" . implode('；', $progress);
        }

        return implode("\n\n", $parts) . "\n\n请输出 JSON：";
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseJson(string $raw): ?array
    {
        $text = trim($raw);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,array{tf:array,latest:\App\Domain\Exchange\Contracts\ExchangeKline,ind:array}> $tfData
     * @param array<string,mixed> $data
     */
    private function printReport(string $symbol, array $data, array $tfData, float $elapsed): void
    {
        $latest = $this->biggestLatest($tfData);
        $refPrice = $latest !== null ? (float) $latest->close : 0;
        $tfNames = implode('/', array_keys($tfData));

        $this->line(str_repeat('═', 60));
        $this->line(sprintf('%s  %s  多周期 AI 研判报告', $symbol, $tfNames));
        $this->line('生成时间: ' . now('UTC')->format('Y-m-d H:i:s') . " UTC  |  AI 耗时: {$elapsed}s");
        $this->line(str_repeat('═', 60));

        $this->newLine();
        $this->line('【走势摘要】');
        $this->line('  ' . ($data['summary'] ?? '(无)'));

        $this->newLine();
        $this->line('【跨周期共振】');
        $this->line('  ' . ($data['multi_tf_resonance'] ?? '(无)'));

        $this->newLine();
        $this->line('【方向判断】');
        $this->line('  趋势:      ' . ($data['trend'] ?? '?'));
        $this->line('  把握度:    ' . ($data['conviction'] ?? '?') . '/100');

        $p = $data['plan'] ?? [];
        $this->newLine();
        $action = strtoupper($p['action'] ?? '?');
        $color = $action === 'WAIT' ? 'yellow' : ($action === 'LONG' ? 'green' : 'red');
        $this->line("【交易计划】<fg={$color}> 动作: {$action}</>");

        if ($action !== 'WAIT') {
            $ez = $p['entry_zone'] ?? [];
            $this->line('  入场区间:  ' . (is_array($ez) ? implode(' - ', $ez) : '?'));
            $this->line('  止损:      ' . ($p['stop_loss'] ?? '?'));
            $this->line('  止盈 1:    ' . ($p['take_profit_1'] ?? '?'));
            $this->line('  止盈 2:    ' . ($p['take_profit_2'] ?? '?'));
            $this->line('  建议仓位:  ' . ($p['position_size_pct'] ?? '?') . '%');
            $this->line('  持仓周期:  ' . ($p['time_horizon'] ?? '?'));
            $this->line('  盈亏比:    ' . ($p['risk_reward_ratio'] ?? '?'));

            if ($refPrice > 0 && is_array($ez) && count($ez) >= 1) {
                $entry = (float) ($ez[0] ?? $refPrice);
                $sl = (float) ($p['stop_loss'] ?? $entry);
                $tp1 = (float) ($p['take_profit_1'] ?? $entry);
                $slDist = abs($entry - $sl);
                $tpDist = abs($tp1 - $entry);
                if ($slDist > 0) {
                    $this->newLine();
                    $this->line('  — 快速计算（参考价 ' . number_format($refPrice, 2) . '）—');
                    $this->line(sprintf('  止损 %.2f → %.1f%%  |  止盈1 %.2f → %.1f%%  |  实际 R:R %.2f',
                        $slDist, $slDist / $entry * 100,
                        $tpDist, $tpDist / $entry * 100,
                        $tpDist / $slDist));
                }
            }
        }
        $this->line('  失效条件:  ' . ($p['invalidates_when'] ?? '(未指定)'));

        $wl = $data['watchlist'] ?? [];
        if (is_array($wl) && $wl !== []) {
            $this->newLine();
            $this->line('【关注清单】');
            foreach ($wl as $i => $item) {
                $this->line('  ' . ($i + 1) . '. ' . (string) $item);
            }
        }

        $this->newLine();
        $this->comment('— 以上由 AI 生成，仅供研究参考，不构成投资建议 —');
    }

    /**
     * @param array<string,array{latest:\App\Domain\Exchange\Contracts\ExchangeKline}> $tfData
     */
    private function biggestLatest(array $tfData): ?object
    {
        // 权重最高的那个（4h 或用户指定的最大周期）
        $best = null;
        foreach ($tfData as $data) {
            if ($best === null || $data['tf']['weight'] > $best['tf']['weight']) {
                $best = $data;
            }
        }

        return $best['latest'] ?? null;
    }
}
