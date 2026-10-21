<?php

declare(strict_types=1);

namespace Tests\Integration\Signal;

use App\Domain\Billing\QuotaGuard;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Signal\IndicatorCalculator;
use App\Domain\Signal\RuleEngineFallback;
use App\Domain\Signal\Contracts\Signal as SignalDto;
use App\Domain\Signal\SignalDispatcher;
use App\Domain\Signal\SignalPipeline;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * T039：入队异常（队列/Redis 故障）不得影响信号落库与通知。
 *
 * SignalPipeline 对 SignalAutoTrader::maybeQueue 的异常必须 try/catch 降级；
 * 本用例断言异常被吞掉、signals 行仍落库、通知仍已分发。
 */
final class SignalAutoFollowQueueDegradationTest extends TestCase
{
    private const TENANT_ID = 990503;

    private const INSTANCE_ID = 990505;

    public function test_queue_failure_does_not_break_signal_persistence(): void
    {
        $now = now();
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-auto-follow-queue',
            'slug' => 'poc-auto-follow-queue',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'pro',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'spot_grid',
            'name' => '现货网格',
            'category' => 'spot',
            'risk_profile' => 'conservative',
            'default_params' => json_encode([]),
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('exchanges')->insert([
            'id' => 990600,
            'code' => 'binance-poc-queue',
            'name' => 'Binance',
            'base_url' => 'https://example.invalid',
            'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $accountId = (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'exchange_id' => 990600,
            'label' => 'poc-auto-follow-queue',
            'market' => 'spot',
            'encrypted_api_key' => 'cipher',
            'encrypted_api_secret' => 'cipher',
            'permissions' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('strategy_instances')->insert([
            'id' => self::INSTANCE_ID,
            'tenant_id' => self::TENANT_ID,
            'strategy_id' => $strategyId,
            'exchange_account_id' => $accountId,
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'params' => json_encode([]),
            'allocated_capital' => '1000',
            'status' => 'running',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $subscriptionId = (int) DB::table('signal_subscriptions')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'name' => 'poc-auto-follow-queue',
            'symbols' => json_encode(['BTCUSDT']),
            'intervals' => json_encode(['5m']),
            'ai_prompter' => 'unsupported-provider',
            'rule_fallback_enabled' => 1,
            'channels' => json_encode(['log']),
            'active' => 1,
            'auto_execute' => 1,
            'execution_instance_id' => self::INSTANCE_ID,
            'execution_min_confidence' => 0,
            'execution_config_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        config(['ai.signal_price_guard.enabled' => false]);

        $this->mock(QuotaGuard::class, function ($mock): void {
            $mock->shouldReceive('assertEntitled')->once()->andReturnNull();
        });

        $this->mock(IndicatorCalculator::class, function ($mock): void {
            $mock->shouldReceive('compute')->once()->andReturn([]);
        });

        $this->mock(RuleEngineFallback::class, function ($mock): void {
            $mock->shouldReceive('analyze')->once()->andReturn(
                new SignalDto('BTCUSDT', '5m', 'long', '100', '110', '95', 80, 'fallback', 'rule_fallback', null),
            );
        });

        $this->mock(SignalDispatcher::class, function ($mock): void {
            $mock->shouldReceive('dispatch')->once();
        });

        // 模拟队列不可用：Job 投递（Bus dispatcher）直接抛异常
        // 同时断言 DTO → Job 的参数回填正确（persistedId/tenantId），防止签名不匹配导致静默丢失
        $this->mock(\Illuminate\Contracts\Bus\Dispatcher::class, function ($mock) use ($subscriptionId): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->with(\Mockery::on(fn (object $job): bool => $job instanceof \App\Jobs\AutoExecuteSignalJob
                    && $job->tenantId === self::TENANT_ID
                    && $job->signalId > 0
                    && $job->subscriptionId === $subscriptionId))
                ->andThrow(new RuntimeException('queue unavailable'));
        });

        $bar = new ExchangeKline(
            exchangeCode: 'binance',
            symbol: 'BTCUSDT',
            interval: '5m',
            openTime: 0,
            closeTime: 299_999,
            open: '100',
            high: '101',
            low: '99',
            close: '100',
            volume: '1',
            quoteVolume: '100',
            tradesCount: 10,
        );

        $subscription = (object) [
            'id' => $subscriptionId,
            'tenant_id' => self::TENANT_ID,
            'ai_prompter' => 'unsupported-provider',
            'rule_fallback_enabled' => 1,
            'channels' => ['log'],
            'auto_execute' => 1,
            'execution_instance_id' => self::INSTANCE_ID,
            'execution_min_confidence' => 0,
            'execution_config_version' => 1,
        ];

        $signal = app(SignalPipeline::class)->run($subscription, $bar, [$bar]);

        self::assertNotNull($signal);
        self::assertSame(1, DB::table('signals')->where('tenant_id', self::TENANT_ID)->count());
        self::assertDatabaseHas('signals', [
            'tenant_id' => self::TENANT_ID,
            'direction' => 'long',
            'source' => 'rule_fallback',
        ]);
    }
}
