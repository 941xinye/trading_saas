<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * P2 POC 种子命令：
 *  - 为 tenant#1 创建/更新一条 active 信号订阅（BTCUSDT / 5m / deepseek / channels=[log]）
 *  - 探测 DeepSeek 连通性（最小 chat/completions ping，不校验信号逻辑）
 *
 * 之后启动：
 *   php artisan market:feed                                # 进程 B：WS+聚合+投 Job
 *   php artisan queue:work --queue=ai,notifications        # AI/通知 worker（普通 PHP 进程）
 */
class PocSignalSetupCommand extends Command
{
    protected $signature = 'poc:signal-setup
        {--tenant=1 : 租户 ID}
        {--symbol=BTCUSDT : 交易对（逗号分隔多个）}
        {--interval=5m : 周期（逗号分隔多个）}
        {--channel=log : 通知渠道（逗号分隔多个，如 log,telegram）}';

    protected $description = 'POC: create active signal subscription for tenant and probe DeepSeek connectivity';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        if ($tenant === null) {
            $this->error("tenant#{$tenantId} 不存在，请先跑 php artisan poc:testnet-setup");

            return self::FAILURE;
        }

        $symbols = array_filter(array_map('trim', explode(',', (string) $this->option('symbol'))));
        $intervals = array_filter(array_map('trim', explode(',', (string) $this->option('interval'))));
        $channels = array_filter(array_map('trim', explode(',', (string) $this->option('channel'))));

        // ------------------------------------------------------------------
        // DeepSeek 连通性探测
        // ------------------------------------------------------------------
        $this->info('Probing DeepSeek connectivity...');
        $apiKey = (string) config('ai.providers.deepseek.api_key');
        if ($apiKey === '') {
            $this->warn('  DEEPSEEK_API_KEY 未配置：信号链路会全部降级到 rule_fallback。');
            $this->line('  在 .env 填入 DEEPSEEK_API_KEY=sk-... 后重新运行本命令验证连通。');
        } else {
            $this->probeDeepSeek($apiKey);
        }

        if (in_array('telegram', $channels, true) && ! (bool) config('notifications.channels.telegram.enabled')) {
            $this->warn('channels 含 telegram 但 TELEGRAM_BOT_TOKEN 未配置，该渠道发送会落 failed（log 渠道不受影响）。');
        }

        // ------------------------------------------------------------------
        // 订阅 upsert（按 tenant+name 幂等）
        // ------------------------------------------------------------------
        $name = 'poc-signal-' . implode('-', $symbols);
        $now = Carbon::now();
        $payload = [
            'symbols' => json_encode(array_values($symbols)),
            'intervals' => json_encode(array_values($intervals)),
            'ai_prompter' => 'deepseek',
            'rule_fallback_enabled' => 1,
            'channels' => json_encode(array_values($channels)),
            'active' => 1,
            'updated_at' => $now,
        ];

        $existing = DB::table('signal_subscriptions')->where('tenant_id', $tenantId)->where('name', $name)->first();
        if ($existing !== null) {
            DB::table('signal_subscriptions')->where('id', $existing->id)->update($payload);
            $id = (int) $existing->id;
            $this->info("Subscription #{$id} updated.");
        } else {
            $id = (int) DB::table('signal_subscriptions')->insertGetId($payload + [
                'tenant_id' => $tenantId,
                'name' => $name,
                'ai_model' => null,
                'created_at' => $now,
            ]);
            $this->info("Subscription #{$id} created.");
        }

        $this->newLine();
        $this->info('Active signal subscription:');
        $this->table(
            ['id', 'tenant', 'symbols', 'intervals', 'prompter', 'channels', 'active'],
            [[$id, $tenantId, implode(',', $symbols), implode(',', $intervals), 'deepseek', implode(',', $channels), '1']],
        );

        $this->newLine();
        $this->line('Next:');
        $this->line('  1) php artisan market:feed                           # WSL 长驻：1m WS→5m 聚合→投 AiAnalyzeJob');
        $this->line('  2) php artisan queue:work --queue=ai,notifications   # WSL 长驻：AI 调用 + 通知发送');
        $this->line('  3) php artisan poc:verify-ai                         # 离线全链路断言（可选）');

        return self::SUCCESS;
    }

    private function probeDeepSeek(string $apiKey): void
    {
        $cfg = (array) config('ai.providers.deepseek');
        $startedAt = microtime(true);

        try {
            $response = Http::timeout((int) ($cfg['timeout'] ?? 30))
                ->acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->post(rtrim((string) $cfg['api_base'], '/') . '/chat/completions', [
                    'model' => $cfg['model'],
                    'messages' => [
                        ['role' => 'user', 'content' => '只回复两个字：在的'],
                    ],
                    'max_tokens' => 16,
                    'temperature' => 0,
                ]);

            $latency = (int) ((microtime(true) - $startedAt) * 1000);

            if ($response->failed()) {
                $this->warn(sprintf(
                    '  DeepSeek 探测失败 HTTP %d (%dms)：%s',
                    $response->status(),
                    $latency,
                    mb_substr($response->body(), 0, 200),
                ));

                return;
            }

            $content = $response->json('choices.0.message.content');
            $this->info(sprintf('  DeepSeek OK（%s，%dms）：%s', $cfg['model'], $latency, is_string($content) ? trim($content) : '(empty)'));
        } catch (\Throwable $e) {
            $this->warn('  DeepSeek 探测异常：' . $e->getMessage());
            $this->line('  国内通常可直连 api.deepseek.com；若 WSL 网络受限，可在 worker 环境设置 HTTP_PROXY/HTTPS_PROXY。');
        }
    }
}
