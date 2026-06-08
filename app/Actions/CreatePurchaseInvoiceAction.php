<?php

namespace App\Actions;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderInvoice;
use App\Services\ExactOnlineService;
use Exception;
use InvalidArgumentException;

final class CreatePurchaseInvoiceAction
{
    public function __construct(
        private readonly ExactOnlineService $exactOnlineService,
        private readonly PurchaseOrderInvoice $invoice,
    ) {}

    /**
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function execute(): string
    {
        $this->validate();

        $exactInvoiceId = $this->exactOnlineService->createPurchaseInvoice($this->invoice);

        if ($exactInvoiceId === false) {
            throw new Exception('Not connected to Exact Online or supplier not configured for sync');
        }

        if ($exactInvoiceId === null) {
            throw new Exception('Failed to create purchase invoice in Exact Online');
        }

        if (! PurchaseOrderInvoice::query()->whereKey($this->invoice->id)->exists()) {
            return $exactInvoiceId;
        }

        if (blank($this->invoice->exact_id)) {
            $this->invoice->forceFill([
                'exact_id' => $exactInvoiceId,
                'exact_synced_at' => now(),
                'exact_error_at' => null,
                'exact_error_message' => null,
            ])->save();
        }

        return $exactInvoiceId;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validate(): void
    {
        if (! $this->invoice->id) {
            throw new InvalidArgumentException('Invoice ID is required');
        }

        if (blank($this->invoice->invoice_number)) {
            throw new InvalidArgumentException('Invoice number is required');
        }

        if ($this->invoice->entry_date === null) {
            throw new InvalidArgumentException('Entry date is required');
        }

        if ($this->invoice->amount === null) {
            throw new InvalidArgumentException('Amount is required');
        }

        if ($this->invoice->total_amount_inc_vat === null) {
            throw new InvalidArgumentException('Total amount including VAT is required');
        }

        if ($this->invoice->exact_id !== null) {
            throw new InvalidArgumentException('Invoice already synced to Exact Online');
        }

        if (! $this->invoice->orderable instanceof PurchaseOrder) {
            throw new InvalidArgumentException('Invoice is not linked to a purchase order');
        }

        if ($this->invoice->orderable->is_cancelled) {
            throw new InvalidArgumentException('Purchase order is cancelled');
        }

        $supplier = $this->invoice->orderable->supplier;

        if ($supplier === null || ! $supplier->sync_with_exact) {
            throw new InvalidArgumentException('Supplier is not configured for Exact Online sync');
        }

        if (blank($supplier->exact_gl_account_id) || blank($supplier->exact_vat_code_id) || blank($supplier->exact_payment_condition_id)) {
            throw new InvalidArgumentException('Supplier Exact Online configuration is incomplete');
        }

        if ($this->invoice->resolveLinkedMedia() === null) {
            throw new InvalidArgumentException('Invoice has no PDF document attached');
        }
    }
}
