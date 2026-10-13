<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Exchange\ExchangeManager;
use Illuminate\Console\Command;
use Swoole\Coroutine;
use Swoole\Runtime;
use Throwable;

/**
 * POC 验证命令（无需 API Key）：验证 Binance 公共 REST + WS K 线订阅链路。
 *
 * 步骤：
 *  1. getTicker BTCUSDT（REST，weight 2）
 *  2. getKline 最近 5 根（REST）
 *  3. subscribeKlineStream 订阅 1m K 线，持续 --duration 秒，
 *     打印每根 closed bar 的 OHLC
 *
 * 通过标准：REST 数据打印正常 + duration 内收到 ≥1 根 closed bar。
 */
class PocVerifyFeedCommand extends Command
{
    protected $signature = 'poc:verify-feed
        {--symbol=BTCUSDT : 交易对}
        {--interval=1m : K 线周期}
        {--duration=60 : WS 观察时长（秒）}';

    protected $description = 'POC: verify Binance public REST ticker/kline + WS kline stream (no API key required)';

    public function handle(): int
    {
        if (! extension_loaded('swoole')) {
            $this->error('Swoole extension not loaded.');

            return self::FAILURE;
        }

        $symbol = (string) $this->option('symbol');
        $interval = (string) $this->option('interval');
        $duration = max(10, (int) $this->option('duration'));

        // Guzzle 7.9 CURLOPT_PREREQFUNCTION 与 Swoole curl hook 不兼容，摘掉 CURL hook（详见 MarketFeedCommand）
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_CURL);

        try {
            Coroutine\run(function () use ($symbol, $interval, $duration): void {
                /** @var ExchangeManager $manager */
                $manager = app(ExchangeManager::class);
                $adapter = $manager->adapter('binance', 'spot');

                $this->info("[1/3] REST getTicker {$symbol}...");
                $ticker = $adapter->getTicker($symbol);
                $this->line(sprintf(
                    '      last=%s bid=%s ask=%s 24h vol=%s change=%s%%',
                    $ticker->lastPrice, $ticker->bidPrice, $ticker->askPrice,
                    $ticker->volume24h, $ticker->changePct24h,
                ));

                $this->info("[2/3] REST getKline {$symbol} {$interval} x5...");
                $klines = $adapter->getKline($symbol, $interval, 5);
                foreach ($klines as $k) {
                    $this->line(sprintf(
                        '      %s O=%s H=%s L=%s C=%s vol=%s',
                        date('H:i:s', (int) ($k->closeTime / 1000)),
                        $k->open, $k->high, $k->low, $k->close, $k->volume,
                    ));
                }

                $this->info("[3/3] WS subscribe {$symbol}@kline_{$interval} ({$duration}s)...");
                $closedBars = 0;
                $subscription = $adapter->subscribeKlineStream($symbol, $interval, function ($bar) use (&$closedBars): void {
                    /** @var \App\Domain\Exchange\Contracts\ExchangeKline $bar */
                    $closedBars++;
                    $this->line(sprintf(
                        '      [closed bar #%d] %s O=%s H=%s L=%s C=%s',
                        $closedBars,
                        date('H:i:s', (int) ($bar->closeTime / 1000)),
                        $bar->open, $bar->high, $bar->low, $bar->close,
                    ));
                });

                $start = time();
                while (time() - $start < $duration) {
                    Coroutine::sleep(1);
                }

                $adapter->unsubscribeStream($subscription);

                if ($closedBars === 0) {
                    $this->warn('No closed bar received within duration. 1m interval needs up to 60s — try --duration=90.');
                } else {
                    $this->info("PASS: REST OK, {$closedBars} closed bar(s) received.");
                }
            });
        } catch (Throwable $e) {
            $this->error('Verify failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
