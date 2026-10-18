<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Exchange\ExchangeManager;
use Illuminate\Console\Command;
use Swoole\Coroutine;
use Swoole\Runtime;
use Throwable;

/**
 * POC 验证命令（无需 API Key）：验证 Bybit 公共 REST + WS K 线订阅链路。
 *
 * spec: specs/002-bybit-spot-adapter（T044，clarify Q3，US1+US2 公共行情 SC-001/SC-002 验收）
 *
 * 步骤：
 *  1. getTicker BTCUSDT（REST，公共 600/5s per IP，免凭据）
 *  2. getKline 最近 5 根（REST，result.list 倒序 → 适配器已翻转为升序）
 *  3. subscribeKlineStream 订阅 1m K 线（wss://stream.bybit.com/v5/public/spot），
 *     持续 --wait 秒，打印每根 confirm:true closed bar 的 OHLC
 *
 * 通过标准：REST 数据打印正常 + wait 内收到 ≥1 根 closed bar。
 *
 * 网络可达性：Bybit 主网端点对国内开发机出口可达性待实测（同 001 OKX POC 备注）；
 * 离线断言见 BybitAdapterTest。
 */
class PocVerifyBybitFeed extends Command
{
    protected $signature = 'poc:verify-bybit-feed
        {--symbol=BTCUSDT : 交易对（平台格式，Bybit symbol 直传）}
        {--interval=1m : K 线周期}
        {--wait=120 : WS 观察时长（秒）}';

    protected $description = 'POC: verify Bybit public REST ticker/kline + WS kline stream (no API key required; spec 002-bybit-spot-adapter)';

    public function handle(): int
    {
        if (! extension_loaded('swoole')) {
            $this->error('Swoole extension not loaded.');

            return self::FAILURE;
        }

        $symbol = (string) $this->option('symbol');
        $interval = (string) $this->option('interval');
        $wait = max(10, (int) $this->option('wait'));

        // Guzzle 7.9 CURLOPT_PREREQFUNCTION 与 Swoole curl hook 不兼容，摘掉 CURL hook（坑1）
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_CURL);

        try {
            Coroutine\run(function () use ($symbol, $interval, $wait): void {
                /** @var ExchangeManager $manager */
                $manager = app(ExchangeManager::class);
                $adapter = $manager->adapter('bybit', 'spot');

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

                $this->info("[3/3] WS subscribe kline.{$interval}.{$symbol} ({$wait}s)...");
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
                while (time() - $start < $wait) {
                    Coroutine::sleep(1);
                }

                $adapter->unsubscribeStream($subscription);

                if ($closedBars === 0) {
                    $this->warn('No closed bar received within duration. 1m interval needs up to 60s — try --wait=120.');
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
