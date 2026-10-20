<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payment\PaymentInvoiceService;
use Illuminate\Console\Command;

/**
 * 非终态过期支付单清扫（FR-012；薄命令，委托 PaymentInvoiceService::expireStale()）。
 *
 * 调度：routes/console.php 每 5 分钟（schedule:list 可查）。
 * 幂等：条件更新 WHERE status IN (pending,processing,partial) AND expires_at < now，
 *       重复执行无副作用；终态单只进不退不受影响。
 */
class BillingExpireInvoicesCommand extends Command
{
    protected $signature = 'billing:expire-invoices';

    protected $description = 'Mark stale non-terminal payment invoices as expired';

    public function handle(PaymentInvoiceService $invoices): int
    {
        $count = $invoices->expireStale();

        $this->info(sprintf('%d invoice(s) expired.', $count));

        return self::SUCCESS;
    }
}
