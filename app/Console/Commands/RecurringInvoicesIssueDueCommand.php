<?php

namespace App\Console\Commands;

use App\Actions\IssueRecurringInvoiceAction;
use App\Models\RecurringInvoice;
use Illuminate\Console\Command;

class RecurringInvoicesIssueDueCommand extends Command
{
    protected $signature = 'recurring-invoices:issue-due {--dry-run : List due records without issuing}';

    protected $description = 'Issue and send invoices for active recurring subscriptions whose next_run_date is due.';

    public function handle(IssueRecurringInvoiceAction $issueRecurringInvoice): int
    {
        $query = RecurringInvoice::query()
            ->where('is_active', true)
            ->whereDate('next_run_date', '<=', now()->toDateString())
            ->orderBy('id');

        if ($this->option('dry-run')) {
            $ids = $query->pluck('id')->all();
            $this->info('Dry run: '.count($ids).' recurring invoice(s) would be processed: '.implode(', ', $ids));

            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;

        $query->each(function (RecurringInvoice $recurring) use ($issueRecurringInvoice, &$processed, &$failed): void {
            if ($issueRecurringInvoice->executeSafe($recurring)) {
                $processed++;
                $this->line('Issued recurring_invoice id '.$recurring->getKey());

                return;
            }

            $failed++;

            if ($recurring->lines()->doesntExist()) {
                $this->warn('Deactivated recurring_invoice id '.$recurring->getKey().' (no lines)');

                return;
            }

            $this->warn('Failed recurring_invoice id '.$recurring->getKey());
        });

        $this->info("Done. Issued: {$processed}, failed: {$failed}.");

        return self::SUCCESS;
    }
}
