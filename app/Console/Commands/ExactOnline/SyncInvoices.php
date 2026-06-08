<?php

namespace App\Console\Commands\ExactOnline;

use App\Enums\OrderGeneralStatus;
use App\Jobs\SyncInvoiceToExactJob;
use App\Models\Order\BaseOrder;
use Illuminate\Console\Command;

class SyncInvoices extends Command
{
    protected $signature = 'exact-online:sync-invoices';

    protected $description = 'Submit unsynced invoices to Exact Online via queued jobs';

    public function handle(): int
    {
        if (! config('exact.enabled')) {
            $this->warn('Exact integration is disabled (EXACT_ENABLED=false).');
            return 0;
        }

        $invoices = BaseOrder::whereIn('type', ['deposit_invoice', 'invoice', 'credit_invoice'])
            ->whereNotNull('order_id')
            ->whereNull('exact_error_at')
            ->where(fn ($q) => $q
                ->where('exact_id', '')
                ->orWhereNull('exact_id')
            )
            ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value])
            ->get();

        $dispatched = 0;

        foreach ($invoices as $invoice) {
            /** @var BaseOrder $invoice */
            if ($invoice->order->getIsCancelled() && $invoice->getType() !== 'credit_invoice') {
                continue;
            }

            SyncInvoiceToExactJob::dispatch($invoice->getId());
            $dispatched++;

            $this->info("Dispatched sync job for {$invoice->getType()->value} ID {$invoice->getId()}");
        }

        if ($dispatched === 0) {
            $this->info('No invoices to submit.');
        } else {
            $this->info("Dispatched {$dispatched} invoice sync job(s).");
        }

        return 0;
    }
}
