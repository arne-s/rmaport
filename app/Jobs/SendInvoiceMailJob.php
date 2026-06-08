<?php

namespace App\Jobs;

use App\Actions\OrderMailEventLogger;
use App\Actions\SendInvoiceMailAction;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderType;
use App\Mail\InvoiceMail;
use App\Models\Order\Invoice;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendInvoiceMailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * One delayed mail dispatch per invoice id (see {@see SyncInvoiceToExactJob}).
     */
    public int $uniqueFor = 3600;

    public function __construct(public int $invoiceId) {}

    public function uniqueId(): string
    {
        return 'send-slot-invoice-mail-' . $this->invoiceId;
    }

    /** @param  int  $delaySeconds  Waittijd vóór verzenden (seconden). */
    public static function dispatchDelayedForInvoice(int $invoiceId, int $delaySeconds = 0): PendingDispatch
    {
        if ($delaySeconds > 0) {
            self::logScheduledInvoiceMail($invoiceId, $delaySeconds);
        }

        return self::dispatch($invoiceId)->delay(now()->addSeconds($delaySeconds));
    }

    private static function logScheduledInvoiceMail(int $invoiceId, int $delaySeconds): void
    {
        $invoice = Invoice::withoutGlobalScopes()->find($invoiceId);
        if ($invoice === null) {
            return;
        }

        app(OrderMailEventLogger::class)->logScheduled(
            $invoice,
            InvoiceMail::class,
            $delaySeconds,
            now()->addSeconds($delaySeconds),
        );
    }

    public function handle(): void
    {
        $invoice = Invoice::withoutGlobalScopes()
            ->where('type', OrderType::Invoice)
            ->whereKey($this->invoiceId)
            ->first();

        if ($invoice === null) {
            Log::warning('SendInvoiceMailJob: slotfactuur niet gevonden of geen type invoice', [
                'invoice_id' => $this->invoiceId,
            ]);

            return;
        }

        if ($invoice->getSentAt() !== null) {
            if ($invoice->getStatus() === OrderGeneralStatus::Pending) {
                $invoice->setStatus(OrderGeneralStatus::Sent);
                $invoice->saveQuietly();
            }

            Log::info('SendInvoiceMailJob: slotfactuur-mail overgeslagen (sent_at al gezet)', [
                'invoice_id' => $this->invoiceId,
            ]);

            return;
        }

        app()->makeWith(SendInvoiceMailAction::class, ['invoice' => $invoice])->execute();

        $invoice->setSentAt(now());
        $invoice->setStatus(OrderGeneralStatus::Sent);
        $invoice->saveQuietly();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('SendInvoiceMailJob failed for invoice ' . $this->invoiceId . ': ' . ($exception?->getMessage() ?? 'unknown'));
    }
}
