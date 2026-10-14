<?php

declare(strict_types=1);

namespace Tests\Integration\Liquidation;

use App\Domain\Exchange\Contracts\LiquidationEvent;
use App\Domain\Exchange\ExchangeManager;
use App\Domain\Liquidation\LiquidationFeedRunner;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Fakes\RecordingLiquidationClusterer;
use Tests\TestCase;

/**
 * 强平事件入库 + 三窗口扇出 + 订阅过滤（合成事件离线测试，迁移主体之一）。
 *
 * LiquidationFeedRunner::onLiquidation 是 private 且类文件顶部 use Swoole\Coroutine，
 * 但该方法本身只依赖 DB/Log/Clusterer，故用反射调用 + RecordingLiquidationClusterer
 * 替身（不连 Redis）即可在无 swoole/Redis/外网环境验证。真实 WS 连通性仍属 smoke，
 * 保留在 poc:liquidation-demo / market:feed 链路不迁移。
 *
 * 注：反射访问 private 属过渡手段；正式接缝建议见迁移报告「需接缝」清单。
 */
class LiquidationEventPersistenceTest extends TestCase
{
    private RecordingLiquidationClusterer $clusterer;

    private LiquidationFeedRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clusterer = new RecordingLiquidationClusterer;
        $this->runner = new LiquidationFeedRunner(app(ExchangeManager::class), $this->clusterer);
    }

    public function test_subscribed_event_is_inserted_with_utc_event_ts(): void
    {
        // 1700000000000ms = 2023-11-14 22:13:20 UTC
        $e = $this->makeEvent('BTCUSDT', 'SELL', '60000.50', '0.5', 1700000000000);

        $this->dispatch($e, ['BTCUSDT']);

        $row = DB::table('liquidation_events')->where('symbol', 'BTCUSDT')->first();
        static::assertNotNull($row);
        static::assertSame('binance', $row->exchange_code);
        static::assertSame('SELL', $row->side);
        static::assertSame(0, bccomp((string) $row->price, '60000.50', 8));
        static::assertSame(0, bccomp((string) $row->qty, '0.5', 8));
        // value_usd 列 decimal(20,2)：30000.25
        static::assertSame(0, bccomp((string) $row->value_usd, '30000.25', 2));
        // 毫秒时间戳转 UTC datetime 落库（不受运行机时区影响）
        static::assertSame('2023-11-14 22:13:20', $row->event_ts);
    }

    public function test_persisted_event_is_fanned_out_to_three_windows(): void
    {
        $e = $this->makeEvent('BTCUSDT', 'SELL', '60000', '0.5');

        $this->dispatch($e, ['BTCUSDT']);

        static::assertSame(['5m', '15m', '60m'], array_column($this->clusterer->ingestCalls, 'window'));
        foreach ($this->clusterer->ingestCalls as $call) {
            static::assertSame('BTCUSDT', $call['symbol']);
            static::assertSame('SELL', $call['side']);
        }
    }

    public function test_event_for_unsubscribed_symbol_is_dropped_silently(): void
    {
        // fstream !forceOrder@arr 是全市场流，未订阅 symbol 必须在落库/聚类前丢弃
        $e = $this->makeEvent('DOGEUSDT', 'SELL', '0.1', '1000');

        $this->dispatch($e, ['BTCUSDT', 'ETHUSDT']);

        static::assertSame(0, DB::table('liquidation_events')->count());
        static::assertSame([], $this->clusterer->ingestCalls);
    }

    public function test_buy_and_sell_events_are_independent_symbol_side_dimensions(): void
    {
        // 交易对/方向维度统计：跨 symbol × side 各落各的行，可按维度独立查询
        $this->dispatch($this->makeEvent('BTCUSDT', 'SELL', '60000', '0.5'), ['BTCUSDT', 'ETHUSDT']);
        $this->dispatch($this->makeEvent('ETHUSDT', 'BUY', '3000', '2'), ['BTCUSDT', 'ETHUSDT']);

        static::assertSame(2, DB::table('liquidation_events')->count());

        $btcSell = DB::table('liquidation_events')->where('symbol', 'BTCUSDT')->where('side', 'SELL')->first();
        $ethBuy = DB::table('liquidation_events')->where('symbol', 'ETHUSDT')->where('side', 'BUY')->first();
        static::assertNotNull($btcSell);
        static::assertNotNull($ethBuy);
        static::assertSame(0, bccomp((string) $btcSell->value_usd, '30000', 2));
        static::assertSame(0, bccomp((string) $ethBuy->value_usd, '6000', 2));

        // 反向维度查询为空，证明两维度不混淆
        static::assertSame(
            0,
            DB::table('liquidation_events')->where('symbol', 'BTCUSDT')->where('side', 'BUY')->count(),
        );
    }

    private function dispatch(LiquidationEvent $e, array $symbols): void
    {
        $method = new ReflectionMethod(LiquidationFeedRunner::class, 'onLiquidation');
        $method->invoke($this->runner, $e, $symbols);
    }

    private function makeEvent(string $symbol, string $side, string $price, string $qty, ?int $tsMs = null): LiquidationEvent
    {
        return new LiquidationEvent(
            exchangeCode: 'binance',
            symbol: $symbol,
            side: $side,
            price: $price,
            qty: $qty,
            valueUsd: bcmul($price, $qty, 8),
            tsMs: $tsMs ?? 1700000000000,
        );
    }
}
