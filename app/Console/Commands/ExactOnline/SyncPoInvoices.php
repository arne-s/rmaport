<?php

namespace App\Console\Commands\ExactOnline;

use App\Actions\CreatePurchaseInvoiceAction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderInvoice;
use App\Services\ExactOnlineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class SyncPoInvoices extends Command
{
    protected $signature = 'exact-online:sync-po-invoices';

    protected $description = 'Sync purchase order invoices to Exact Online';

    public function handle(ExactOnlineService $exactOnlineService): int
    {
        if (! $exactOnlineService->ensureAccessTokenForApi()) {
            $this->error('Could not obtain Exact Online access token.');

            return self::FAILURE;
        }

        $invoices = PurchaseOrderInvoice::query()
            ->whereNull('exact_id')
            ->whereNull('paid_at')
            ->whereNotNull('invoice_number')
            ->whereNotNull('entry_date')
            ->whereNotNull('amount')
            ->whereNotNull('total_amount_inc_vat')
            ->where('orderable_type', PurchaseOrder::class)
            ->whereHasMorph('orderable', [PurchaseOrder::class], fn ($query) => $query->where('is_cancelled', false))
            ->get();

        if ($invoices->isEmpty()) {
            $this->info('No purchase order invoices to sync.');

            return self::SUCCESS;
        }

        $this->info('Syncing ' . $invoices->count() . ' purchase order invoice(s) to Exact Online...');

        $synced = 0;
        $failed = 0;

        foreach ($invoices as $invoice) {
            try {
                (new CreatePurchaseInvoiceAction($exactOnlineService, $invoice))->execute();
                $synced++;
                $this->line("Synced invoice {$invoice->invoice_number} (ID: {$invoice->id})");
            } catch (InvalidArgumentException $exception) {
                $failed++;
                $this->error("Failed to sync invoice {$invoice->invoice_number} (ID: {$invoice->id}): {$exception->getMessage()}");
                Log::error('Failed to sync purchase order invoice to Exact Online', [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'error' => $exception->getMessage(),
                ]);
            } catch (Throwable $exception) {
                $failed++;
                $this->error("Failed to sync invoice {$invoice->invoice_number} (ID: {$invoice->id}): {$exception->getMessage()}");
                Log::error('Failed to sync purchase order invoice to Exact Online', [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Sync complete. Synced: {$synced}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
