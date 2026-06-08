<?php

namespace App\Console\Commands;

use App\Enums\OrderGeneralStatus;
use App\Jobs\SendDepositInvoiceMailJob;
use App\Models\Order\DepositInvoice;
use App\Models\Order\Main;
use App\Models\Order\Order;
use Illuminate\Console\Command;

class RetryDepositInvoiceMailsCommand extends Command
{
    protected $signature = 'orders:retry-deposit-mails
                            {--main= : Only retry for this main (aanvraag) id}
                            {--dry-run : List matches without queueing jobs}';

    protected $description = 'Queue SendDepositInvoiceMailJob for deposit invoices that were never emailed (sent_at is null).';

    public function handle(): int
    {
        $query = DepositInvoice::query()
            ->whereNull('sent_at')
            ->whereNotIn('status', [
                OrderGeneralStatus::Initial->value,
                OrderGeneralStatus::Draft->value,
                OrderGeneralStatus::Cancelled->value,
            ]);

        if ($mainId = $this->option('main')) {
            $query->where('main_id', (int) $mainId);
        }

        $deposits = $query->orderBy('id')->get();

        if ($deposits->isEmpty()) {
            $this->info('No unsent deposit invoices found.');

            return self::SUCCESS;
        }

        $queued = 0;
        $skipped = 0;

        foreach ($deposits as $deposit) {
            $order = $this->resolveOrderForDeposit($deposit);

            if ($order === null) {
                $this->warn("Skipping deposit invoice {$deposit->getId()} (main {$deposit->main_id}): no order found.");
                $skipped++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("Would queue mail for deposit {$deposit->getUidFormatted()} (deposit id {$deposit->getId()}, order id {$order->getId()}, main {$deposit->main_id}).");
                $queued++;

                continue;
            }

            SendDepositInvoiceMailJob::dispatch($order->getId());
            $this->info("Queued deposit mail for {$deposit->getUidFormatted()} (order id {$order->getId()}).");
            $queued++;
        }

        $suffix = $this->option('dry-run') ? ' (dry run)' : '';
        $this->info("Done{$suffix}: {$queued} queued, {$skipped} skipped.");

        return self::SUCCESS;
    }

    private function resolveOrderForDeposit(DepositInvoice $deposit): ?Order
    {
        $linkedOrder = Order::withoutGlobalScopes()
            ->where('deposit_invoice_id', $deposit->getId())
            ->first();

        if ($linkedOrder instanceof Order) {
            return SendDepositInvoiceMailJob::resolveOrderForDepositMail($linkedOrder);
        }

        if ($deposit->main_id === null) {
            return null;
        }

        $main = Main::withoutGlobalScopes()->find($deposit->main_id);
        if ($main === null) {
            return null;
        }

        $latest = $main->getLatestOrderForInvoicing();
        if ($latest instanceof Order) {
            return SendDepositInvoiceMailJob::resolveOrderForDepositMail($latest);
        }

        return null;
    }
}
