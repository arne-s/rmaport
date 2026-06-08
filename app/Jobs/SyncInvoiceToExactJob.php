<?php

namespace App\Jobs;

use App\Enums\OrderType;
use App\Models\AppSyncMessage;
use App\Models\Order\BaseOrder;
use App\Models\Order\CreditInvoice;
use App\Models\Order\DepositInvoice;
use App\Models\Order\Invoice;
use App\Models\User;
use App\Services\Exact\Invoices\ExactSalesEntry;
use App\Services\ExactOnlineService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncInvoiceToExactJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Prevent overlapping queue dispatches for the same invoice; retries of one job still use the same unique id.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        public int $invoiceId,
        public ?int $userId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'exact-invoice-sync-' . $this->invoiceId;
    }

    public function handle(): void
    {
        $invoice = BaseOrder::query()->find($this->invoiceId);
        if ($invoice === null) {
            return;
        }

        if ($invoice->getExactId()) {
            return;
        }

        $invoice = match ($invoice->getType()) {
            OrderType::DepositInvoice => DepositInvoice::find($this->invoiceId),
            OrderType::CreditInvoice => CreditInvoice::find($this->invoiceId),
            OrderType::Invoice => Invoice::find($this->invoiceId),
            default => $invoice,
        } ?? $invoice;

        if (! config('exact.enabled')) {
            return;
        }

        /** @var ExactOnlineService $exact */
        $exact = app('exact');
        $salesEntry = new ExactSalesEntry($exact);

        $user = $this->userId ? User::query()->find($this->userId) : null;

        try {
            $result = $salesEntry->submitSalesEntry($invoice);
            if ($result === null) {
                return;
            }

            $syncedInvoice = $result['invoice'];

            $this->createOrderEvent($syncedInvoice, $result);

            if ($user !== null) {
                AppSyncMessage::queueForUser(
                    $user->id,
                    AppSyncMessage::KIND_EXACT_INVOICE_SYNC,
                    AppSyncMessage::STATUS_SUCCESS,
                    'Factuur gesynchroniseerd met Exact',
                    "Factuur #{$syncedInvoice->getUidFormatted()} is succesvol gesynchroniseerd.",
                    ['invoice_id' => $syncedInvoice->getId()],
                );
            }
        } catch (Throwable $e) {
            Log::driver('exact-online')->error(
                "SyncInvoiceToExactJob failed for invoice {$this->invoiceId} (attempt {$this->attempts()}/{$this->tries}): {$e->getMessage()}"
            );

            if ($this->attempts() >= $this->tries) {
                $invoice->setExactErrorAt(now());
                $invoice->save();

                if ($user !== null) {
                    AppSyncMessage::queueForUser(
                        $user->id,
                        AppSyncMessage::KIND_EXACT_INVOICE_SYNC,
                        AppSyncMessage::STATUS_FAILURE,
                        'Factuur niet gesynchroniseerd met Exact',
                        "Factuur #{$invoice->getUidFormatted()}: {$e->getMessage()}",
                        ['invoice_id' => $invoice->getId()],
                    );
                }
            }

            throw $e;
        }
    }

    /**
     * @param array{invoice: BaseOrder, entry_number: int|null, journal: string|null, amount: float|null} $result
     */
    private function createOrderEvent(BaseOrder $invoice, array $result): void
    {
        $main = $invoice->getMain() ?? $invoice;

        $uid = $invoice->getUidFormatted() ?: $invoice->getId();
        $typeName = $invoice->getType()?->getLabel() ?? 'Factuur';

        $parts = ["{$typeName} {$uid} geboekt in Exact Online"];
        if ($result['entry_number']) {
            $parts[] = "boekstuknr. {$result['entry_number']}";
        }

        $main->orderEvents()->create([
            'type' => implode(' — ', $parts),
            'data' => array_filter([
                'invoice_id' => $invoice->getId(),
                'entry_number' => $result['entry_number'],
                'journal' => $result['journal'],
                'amount' => $result['amount'],
                'exact_id' => $invoice->getExactId(),
            ], fn ($v) => $v !== null),
            'user_id' => null,
        ]);
    }
}
