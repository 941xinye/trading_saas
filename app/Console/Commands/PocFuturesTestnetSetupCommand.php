<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\EntitlementException;
use App\Domain\Billing\Metering\QuotaExceededException;
use App\Domain\Billing\QuotaGuard;
use App\Domain\Exchange\Adapters\BinanceFuturesAdapter;
use App\Domain\Exchange\ExchangeManager;
use App\Domain\Tenancy\TenantKeyResolver;
use App\Support\AesGcmCrypt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * spec 005 T043 — 合约 testnet 装配命令。
 *
 * 一键拉起 Binance futures testnet 合约网格实例：
 *   1. 确保 tenant / strategies(futures_grid) / exchanges(binance) 基础行
 *   2. exchange_accounts：testnet key 加密落库（market=futures）
 *   3. 创建 strategy_instance（futures_grid，含 leverage 参数）
 *   4. 调用 adapter 设置杠杆 + 逐仓模式
 *
 * 前置：.env 配置 EXCHANGE_TESTNET_KEY/SECRET（futures testnet）。
 */
class PocFuturesTestnetSetupCommand extends Command
{
    protected $signature = 'poc:futures-testnet-setup
        {--symbol=BTCUSDT : 交易对}
        {--interval=1m : K 线周期}
        {--grid-count=10 : 格子数量}
        {--margin=100 : 保证金（USDT）}
        {--leverage=10 : 杠杆倍数}
        {--upper= : 网格上沿价格（默认现价 ×1.02）}
        {--lower= : 网格下沿价格（默认现价 ×0.98）}';

    protected $description = 'POC: provision Binance futures testnet grid instance + set leverage + isolated margin';

    public function handle(TenantKeyResolver $keyResolver, AesGcmCrypt $crypt, ExchangeManager $manager, QuotaGuard $quotaGuard): int
    {
        $symbol = (string) $this->option('symbol');
        $interval = (string) $this->option('interval');
        $gridCount = max(1, (int) $this->option('grid-count'));
        $margin = (string) $this->option('margin');
        $leverage = max(1, (int) $this->option('leverage'));

        // ---- 1. 基础行 ------------------------------------------------
        $tenant = DB::table('tenants')->orderBy('id')->first();
        if ($tenant === null) {
            $tenantId = (int) DB::table('tenants')->insertGetId([
                'name' => 'POC Futures Tenant',
                'slug' => 'poc-futures',
                'exchange_dek' => '',
                'status' => 'active',
                'plan_code' => 'pro', // 合约需 pro 及以上
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("Created tenant #{$tenantId} (POC Futures, plan=pro)");
        } else {
            $tenantId = (int) $tenant->id;
            // 确保租户套餐支持 futures_grid
            if ($tenant->plan_code === 'free') {
                DB::table('tenants')->where('id', $tenantId)->update(['plan_code' => 'pro']);
                $this->warn("Tenant #{$tenantId} plan upgraded free → pro (futures_grid requires pro+)");
            }
        }

        try {
            $quotaGuard->assertEntitled($tenantId);
            $quotaGuard->assertStrategyAllowed($tenantId, 'futures_grid');
        } catch (EntitlementException $e) {
            $this->error('装配被权益/策略门控拦截：'.$e->getMessage());

            return self::FAILURE;
        }

        // 策略目录
        $strategy = DB::table('strategies')->where('code', 'futures_grid')->first();
        if ($strategy === null) {
            $strategyId = (int) DB::table('strategies')->insertGetId([
                'code' => 'futures_grid',
                'name' => '合约网格',
                'category' => 'futures',
                'risk_profile' => 'medium',
                'default_params' => json_encode([
                    'upper_price' => null, 'lower_price' => null,
                    'grid_count' => $gridCount, 'margin' => $margin,
                    'leverage' => $leverage,
                ]),
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("Created strategy futures_grid #{$strategyId}");
        } else {
            $strategyId = (int) $strategy->id;
        }

        // 交易所
        $exchange = DB::table('exchanges')->where('code', 'binance')->first();
        if ($exchange === null) {
            $exchangeId = (int) DB::table('exchanges')->insertGetId([
                'code' => 'binance',
                'name' => 'Binance',
                'base_url' => 'https://fapi.binance.com',
                'ws_url' => 'wss://fstream.binance.com',
                'markets' => json_encode(['spot', 'futures']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $exchangeId = (int) $exchange->id;
        }

        // ---- 2. 合约交易所账户 ----------------------------------------
        $apiKey = (string) env('EXCHANGE_TESTNET_KEY');
        $apiSecret = (string) env('EXCHANGE_TESTNET_SECRET');
        if ($apiKey === '' || $apiSecret === '') {
            $this->warn('EXCHANGE_TESTNET_KEY/SECRET not set — account row will be created empty.');
            $this->line('Get futures testnet keys at https://testnet.binancefuture.com, fill .env, then re-run.');
        }

        $dek = $keyResolver->tenantDek($tenantId);
        $encKey = $apiKey !== '' ? $crypt->encrypt($apiKey, $dek) : '';
        $encSecret = $apiSecret !== '' ? $crypt->encrypt($apiSecret, $dek) : '';

        $account = DB::table('exchange_accounts')
            ->where('tenant_id', $tenantId)
            ->where('exchange_id', $exchangeId)
            ->where('label', 'futures-testnet')
            ->first();
        if ($account === null) {
            try {
                $quotaGuard->assertResourceCount(
                    $tenantId,
                    'max_exchange_accounts',
                    (int) DB::table('exchange_accounts')->where('tenant_id', $tenantId)->count(),
                );
            } catch (QuotaExceededException $e) {
                $this->error("交易所账户数超套餐上限（{$e->used}/{$e->limit}）");

                return self::FAILURE;
            }
            $accountId = (int) DB::table('exchange_accounts')->insertGetId([
                'tenant_id' => $tenantId,
                'exchange_id' => $exchangeId,
                'label' => 'futures-testnet',
                'market' => 'futures',
                'encrypted_api_key' => $encKey,
                'encrypted_api_secret' => $encSecret,
                'permissions' => json_encode(['read', 'trade']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("Created futures exchange account #{$accountId}");
        } else {
            $accountId = (int) $account->id;
            if ($encKey !== '') {
                DB::table('exchange_accounts')->where('id', $accountId)->update([
                    'encrypted_api_key' => $encKey,
                    'encrypted_api_secret' => $encSecret,
                    'updated_at' => now(),
                ]);
            }
        }

        // ---- 3. 合约网格实例 ------------------------------------------
        /** @var BinanceFuturesAdapter $adapter */
        $adapter = $manager->adapter('binance', 'futures');
        $price = $adapter->getTicker($symbol)->lastPrice;
        $upper = (string) ($this->option('upper') ?: bcmul($price, '1.02', 8));
        $lower = (string) ($this->option('lower') ?: bcmul($price, '0.98', 8));

        $params = [
            'symbol' => $symbol,
            'upper_price' => $upper,
            'lower_price' => $lower,
            'grid_count' => $gridCount,
            'margin' => $margin,
            'leverage' => $leverage,
        ];

        $existing = DB::table('strategy_instances')
            ->where('tenant_id', $tenantId)
            ->where('strategy_id', $strategyId)
            ->where('symbol', $symbol)
            ->first();
        if ($existing === null) {
            try {
                $quotaGuard->assertResourceCount(
                    $tenantId,
                    'max_strategy_instances',
                    (int) DB::table('strategy_instances')->where('tenant_id', $tenantId)->count(),
                );
            } catch (QuotaExceededException $e) {
                $this->error("策略实例数超套餐上限（{$e->used}/{$e->limit}）");

                return self::FAILURE;
            }
            $instanceId = (int) DB::table('strategy_instances')->insertGetId([
                'tenant_id' => $tenantId,
                'strategy_id' => $strategyId,
                'exchange_account_id' => $accountId,
                'symbol' => $symbol,
                'interval' => $interval,
                'params' => json_encode($params),
                'allocated_capital' => $margin,
                'leverage' => $leverage,
                'status' => 'stopped',
                'breaker_state' => 'closed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("Created futures grid instance #{$instanceId}");
        } else {
            $instanceId = (int) $existing->id;
            DB::table('strategy_instances')->where('id', $instanceId)->update([
                'exchange_account_id' => $accountId,
                'interval' => $interval,
                'params' => json_encode($params),
                'allocated_capital' => $margin,
                'leverage' => $leverage,
                'updated_at' => now(),
            ]);
            $this->info("Updated futures grid instance #{$instanceId}");
        }

        // ---- 4. 设置杠杆 + 逐仓模式 -----------------------------------
        if ($apiKey !== '' && $apiSecret !== '') {
            try {
                $adapter->setLeverage($symbol, $leverage);
                $this->info("Set leverage {$leverage}x for {$symbol}");

                $adapter->setMarginType($symbol);
                $this->info("Set margin type ISOLATED for {$symbol}");
            } catch (\Throwable $e) {
                $this->warn('Set leverage/margin type failed: '.$e->getMessage());
            }
        } else {
            $this->warn('Skip setLeverage/setMarginType (no testnet keys)');
        }

        // 摘要
        $step = bcdiv(bcsub($upper, $lower, 8), (string) $gridCount, 8);
        $this->table(['item', 'value'], [
            ['tenant', $tenantId],
            ['instance', $instanceId],
            ['symbol', $symbol],
            ['current price', $price],
            ['grid range', "{$lower} ~ {$upper}"],
            ['grid step', $step],
            ['margin', $margin.' USDT'],
            ['leverage', $leverage.'x'],
            ['margin type', 'isolated'],
            ['status', 'stopped'],
        ]);

        $this->line('Instance ID: '.$instanceId);
        $this->line('Next: start the instance via StrategyRunner, then run poc:futures-risk-simulate');

        return self::SUCCESS;
    }
}
