<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Exchange\ExchangeManager;
use App\Domain\Tenancy\TenantKeyResolver;
use App\Support\AesGcmCrypt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * POC 环境准备命令：一键拉起 Binance testnet 网格实例。
 *
 * 步骤：
 *  1. 确保 tenant（首个）/ strategies(spot_grid) / exchanges(binance) 基础行
 *  2. exchange_accounts：env EXCHANGE_TESTNET_KEY/SECRET 经租户 DEK 加密落库
 *  3. 拉取当前价计算网格区间（默认 ±2%、10 格），创建/更新 strategy_instance
 *     status=running → 供 market:feed 调度
 *
 * 前置：.env 已配置 EXCHANGE_MASTER_KEY + EXCHANGE_TESTNET_KEY/SECRET
 *（testnet 注册：https://testnet.binance.vision）。
 */
class PocTestnetSetupCommand extends Command
{
    protected $signature = 'poc:testnet-setup
        {--symbol=BTCUSDT : 交易对}
        {--interval=1m : K 线周期}
        {--grid-count=10 : 格子数量}
        {--investment=100 : 投入资金（USDT）}
        {--upper= : 网格上沿价格（默认现价 ×1.02）}
        {--lower= : 网格下沿价格（默认现价 ×0.98）}';

    protected $description = 'POC: provision tenant/account/instance for Binance spot testnet grid strategy';

    public function handle(TenantKeyResolver $keyResolver, AesGcmCrypt $crypt, ExchangeManager $manager): int
    {
        $symbol = (string) $this->option('symbol');
        $interval = (string) $this->option('interval');
        $gridCount = max(1, (int) $this->option('grid-count'));
        $investment = (string) $this->option('investment');

        // ---- 1. 基础行 ------------------------------------------------
        $tenant = DB::table('tenants')->orderBy('id')->first();
        if ($tenant === null) {
            $tenantId = (int) DB::table('tenants')->insertGetId([
                'name' => 'POC Tenant',
                'slug' => 'poc',
                'exchange_dek' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("Created tenant #{$tenantId} (POC Tenant)");
        } else {
            $tenantId = (int) $tenant->id;
        }

        $strategy = DB::table('strategies')->where('code', 'spot_grid')->first();
        if ($strategy === null) {
            $strategyId = (int) DB::table('strategies')->insertGetId([
                'code' => 'spot_grid',
                'name' => '现货网格',
                'category' => 'spot',
                'risk_profile' => 'conservative',
                'default_params' => json_encode([
                    'upper_price' => null, 'lower_price' => null,
                    'grid_count' => $gridCount, 'investment_amount' => $investment,
                ]),
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("Created strategy spot_grid #{$strategyId}");
        } else {
            $strategyId = (int) $strategy->id;
        }

        $exchange = DB::table('exchanges')->where('code', 'binance')->first();
        if ($exchange === null) {
            $exchangeId = (int) DB::table('exchanges')->insertGetId([
                'code' => 'binance',
                'name' => 'Binance',
                'base_url' => 'https://api.binance.com',
                'ws_url' => 'wss://stream.binance.com:9443/stream',
                'markets' => json_encode(['spot', 'futures']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("Created exchange binance #{$exchangeId}");
        } else {
            $exchangeId = (int) $exchange->id;
        }

        // ---- 2. 交易所账户（testnet key 加密落库）----------------------
        $apiKey = (string) env('EXCHANGE_TESTNET_KEY');
        $apiSecret = (string) env('EXCHANGE_TESTNET_SECRET');
        if ($apiKey === '' || $apiSecret === '') {
            $this->warn('EXCHANGE_TESTNET_KEY/SECRET not set — account row will be created empty.');
            $this->line('Get testnet keys at https://testnet.binance.vision, fill .env, then re-run.');
        }

        $dek = $keyResolver->tenantDek($tenantId); // 首次访问懒生成 DEK
        $encKey = $apiKey !== '' ? $crypt->encrypt($apiKey, $dek) : '';
        $encSecret = $apiSecret !== '' ? $crypt->encrypt($apiSecret, $dek) : '';

        $account = DB::table('exchange_accounts')
            ->where('tenant_id', $tenantId)
            ->where('exchange_id', $exchangeId)
            ->where('label', 'testnet')
            ->first();
        if ($account === null) {
            $accountId = (int) DB::table('exchange_accounts')->insertGetId([
                'tenant_id' => $tenantId,
                'exchange_id' => $exchangeId,
                'label' => 'testnet',
                'market' => 'spot',
                'encrypted_api_key' => $encKey,
                'encrypted_api_secret' => $encSecret,
                'permissions' => json_encode(['spot']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("Created exchange account #{$accountId} (testnet)");
        } else {
            $accountId = (int) $account->id;
            if ($encKey !== '') {
                DB::table('exchange_accounts')->where('id', $accountId)->update([
                    'encrypted_api_key' => $encKey,
                    'encrypted_api_secret' => $encSecret,
                    'updated_at' => now(),
                ]);
                $this->info("Updated exchange account #{$accountId} credentials");
            }
        }

        // ---- 3. 网格实例 ----------------------------------------------
        $adapter = $manager->adapter('binance', 'spot');
        $price = $adapter->getTicker($symbol)->lastPrice;
        $upper = (string) ($this->option('upper') ?: bcmul($price, '1.02', 8));
        $lower = (string) ($this->option('lower') ?: bcmul($price, '0.98', 8));

        $params = json_encode([
            'upper_price' => $upper,
            'lower_price' => $lower,
            'grid_count' => $gridCount,
            'investment_amount' => $investment,
        ]);

        $existing = DB::table('strategy_instances')
            ->where('tenant_id', $tenantId)
            ->where('strategy_id', $strategyId)
            ->where('symbol', $symbol)
            ->first();
        if ($existing === null) {
            $instanceId = (int) DB::table('strategy_instances')->insertGetId([
                'tenant_id' => $tenantId,
                'strategy_id' => $strategyId,
                'exchange_account_id' => $accountId,
                'symbol' => $symbol,
                'interval' => $interval,
                'params' => $params,
                'allocated_capital' => $investment,
                'status' => 'running',
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("Created strategy instance #{$instanceId} (running)");
        } else {
            $instanceId = (int) $existing->id;
            DB::table('strategy_instances')->where('id', $instanceId)->update([
                'exchange_account_id' => $accountId,
                'interval' => $interval,
                'params' => $params,
                'allocated_capital' => $investment,
                'status' => 'running',
                'updated_at' => now(),
            ]);
            $this->info("Updated strategy instance #{$instanceId} (running)");
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
            ['per grid quote', bcdiv($investment, (string) $gridCount, 8) . ' USDT'],
            ['interval', $interval],
            ['testnet', (string) env('EXCHANGE_BINANCE_TESTNET', 'false')],
        ]);

        $this->line('Next: php artisan market:feed');

        return self::SUCCESS;
    }
}
