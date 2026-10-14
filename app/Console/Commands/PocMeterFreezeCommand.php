<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Metering\TwoPhaseMeter;
use App\Domain\Billing\QuotaGuard;
use Illuminate\Console\Command;

/**
 * P3 POC 内部命令：一次性占额（用于并发原子性测试，多进程并行打同一个配额桶）。
 *
 * 输出：ALLOWED <count> 或 DENIED <count>
 */
class PocMeterFreezeCommand extends Command
{
    protected $signature = 'poc:meter-freeze
        {tenant : 租户 ID}
        {event=ai_call : 事件类型（ai_call/order_placed）}
        {--limit= : 覆盖套餐配额（并发测试用）}
        {--ref= : 幂等 ref（缺省唯一）}';

    protected $description = 'POC internal: one-shot quota freeze for concurrency test';

    protected $hidden = true;

    public function handle(QuotaGuard $guard, TwoPhaseMeter $meter): int
    {
        $tenantId = (int) $this->argument('tenant');
        $event = (string) $this->argument('event');
        $ref = (string) ($this->option('ref') ?: sprintf('conc-%s-%d', getmypid(), random_int(1000, 9999)));

        if ($this->option('limit') !== null) {
            // 直接绕过套餐限额（仅 POC 并发验证用）
            $event = $this->meterWithOverride($meter, $tenantId, $event, $ref, (int) $this->option('limit'));
            if ($event === null) {
                $this->output->write('DENIED ' . $meter->usedToday($tenantId, (string) $this->argument('event')));

                return self::SUCCESS;
            }
            $this->output->write('ALLOWED ' . $meter->usedToday($tenantId, (string) $this->argument('event')));

            return self::SUCCESS;
        }

        try {
            $guard->freezeDaily($tenantId, $event, $ref);
            $this->output->write('ALLOWED ' . $meter->usedToday($tenantId, $event));
        } catch (\Throwable) {
            $this->output->write('DENIED ' . $meter->usedToday($tenantId, $event));
        }

        return self::SUCCESS;
    }

    private function meterWithOverride(
        TwoPhaseMeter $meter,
        int $tenantId,
        string $event,
        string $ref,
        int $limit,
    ): ?\App\Models\MeterEvent {
        return $meter->freeze($tenantId, $event, $ref, $limit);
    }
}
