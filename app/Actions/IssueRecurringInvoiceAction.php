<?php

namespace App\Actions;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderType;
use App\Enums\PaymentTerms;
use App\Jobs\SyncInvoiceToExactJob;
use App\Filament\Resources\InvoiceResource\Actions\SubmitInvoiceEmailAction;
use App\Models\Document;
use App\Models\ExactPaymentCondition;
use App\Models\ExactVATCode;
use App\Models\Order\Invoice;
use App\Models\OrderProduct;
use App\Models\RecurringInvoice;
use App\Support\RecurringInvoiceSchedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class IssueRecurringInvoiceAction
{
    /**
     * @throws Throwable
     */
    public function execute(RecurringInvoice $recurring): void
    {
        if (! $recurring->getIsActive()) {
            throw new InvalidArgumentException('Recurring invoice is not active.');
        }

        if ($recurring->lines()->doesntExist()) {
            throw new InvalidArgumentException('Recurring invoice has no lines.');
        }

        if ($recurring->getExactVatCode() === '') {
            throw new InvalidArgumentException('Recurring invoice exact_vat_code is required.');
        }

        if ($recurring->getExactPaymentCondition() === '') {
            throw new InvalidArgumentException('Recurring invoice exact_payment_condition is required.');
        }

        $vatCodeRow = ExactVATCode::query()->where('code', $recurring->getExactVatCode())->first();
        if ($vatCodeRow === null) {
            throw new InvalidArgumentException('Unknown exact_vat_code: '.$recurring->getExactVatCode());
        }

        $vatPercent = $vatCodeRow->percentageAsPercent();

        DB::transaction(function () use ($recurring, $vatPercent): void {
            /** @var Invoice $invoice */
            $invoice = Invoice::withoutGlobalScopes()->create([
                'type'                   => OrderType::Invoice->value,
                'customer_id'            => $recurring->getBillingCustomerId(),
                'shipping_customer_id'   => $recurring->getBillingCustomerId(),
                'billing_customer_id'    => $recurring->getBillingCustomerId(),
                'status'                 => OrderGeneralStatus::Initial->value,
                'main_id'                => null,
                'order_id'               => null,
                'payment_terms'          => PaymentTerms::Postpay->value,
                'order_reference'        => $recurring->getReference(),
                'order_comment'          => $recurring->getComments(),
                'author_id'              => $recurring->getAuthorId(),
                'recurring_order_id'     => $recurring->getKey(),
                'additional'             => array_merge(
                    $recurring->getAdditional(),
                    [
                        'exact_vat_code'          => $recurring->getExactVatCode(),
                        'exact_payment_condition'  => $recurring->getExactPaymentCondition(),
                    ]
                ),
            ]);

            $sort = 0;
            foreach ($recurring->lines as $line) {
                $sort++;
                $qty = (float) $line->qty;
                if ($qty <= 0) {
                    throw new InvalidArgumentException('Line qty must be greater than zero.');
                }

                $discountPct = (float) $line->company_sales_price_discount_percentage;
                $base = (float) $line->company_sales_price_base;
                $purchaseBase = (float) $line->company_purchase_price_base;
                $lineSalesSubtotal = $base * $qty;
                $salesDiscountAmount = $lineSalesSubtotal * ($discountPct / 100);
                $salesTotal = $lineSalesSubtotal - $salesDiscountAmount;
                $purchaseLineTotal = $purchaseBase * $qty;

                $orderProduct = OrderProduct::query()->create([
                    'product_id' => $line->product_id,
                    'value' => $line->value,
                    'qty' => $qty,
                    'order_id' => $invoice->getId(),
                    'sort' => $sort,
                    'company_purchase_price_base' => $purchaseBase,
                    'company_purchase_price_additional' => 0,
                    'company_purchase_price_subtotal' => round($purchaseBase, 2),
                    'company_purchase_price_total' => round($purchaseLineTotal, 2),
                    'company_sales_price_base' => $base,
                    'company_sales_price_additional' => 0,
                    'company_sales_price_subtotal' => round($lineSalesSubtotal, 2),
                    'company_sales_price_total' => round($salesTotal, 2),
                    'company_sales_price_discount_percentage' => $discountPct,
                    'company_sales_price_discount' => (string) round($salesDiscountAmount, 2),
                    'attribute_summary_basic' => $line->attribute_summary_basic,
                    'supplier_id' => $line->supplier_id,
                    'vat' => $vatPercent,
                ]);
                $orderProduct->setFulfillmentTypeBasedOnProduct();
                $orderProduct->save();
            }

            $invoice->refresh();
            $invoice->setInitialPaymentAmount();
            if ($invoice->getUid() === null || $invoice->getUid() === '') {
                $invoice->setUid($invoice->getNewUid());
            }
            $invoice->save();

            $paymentConditionRow = ExactPaymentCondition::query()
                ->where('code', $recurring->getExactPaymentCondition())
                ->first();
            if ($paymentConditionRow === null) {
                throw new InvalidArgumentException(
                    'Onbekende betalingsconditie (exact_payment_condition): '.$recurring->getExactPaymentCondition()
                );
            }
            $paymentDaysForDue = (int) $paymentConditionRow->payment_days;
            if ($paymentDaysForDue < 1) {
                throw new InvalidArgumentException(
                    'Betalingsconditie '.$recurring->getExactPaymentCondition().' heeft geen geldig aantal dagen (payment_days) in Exact.'
                );
            }

            $issuedAt = now();
            $invoice->setSentAt($issuedAt);
            $invoice->setExpiresAt($issuedAt->copy()->addDays($paymentDaysForDue));
            $invoice->save();

            Document::createFromOrder($invoice);

            $invoice->refresh();
            $invoice->loadMissing(['customer', 'billingCustomer']);
            $invoice->getOrCreatePublicDownloadUuid();

            if ($this->recurringUsesStoredEmailTemplate($recurring)) {
                $toKeys = SubmitInvoiceEmailAction::defaultRecipientKeysForInvoice($invoice);
                $toEmails = SubmitInvoiceEmailAction::resolveRecipientEmailsForInvoice($invoice, $toKeys);
                $ccEmails = SubmitInvoiceEmailAction::resolveRecipientEmailsForInvoice($invoice, $recurring->getEmailCcKeys());
                $ccEmails = SendInvoiceMailAction::mergeExecuteStyleDealerCcWhenCustomerOnTo($invoice, $toKeys, $ccEmails);
                $bccEmails = SubmitInvoiceEmailAction::resolveRecipientEmailsForInvoice($invoice, $recurring->getEmailBccKeys());

                if ($toEmails === []) {
                    throw new InvalidArgumentException(
                        'Geen geldige To-ontvanger voor factuur uit abonnement (invoice id '.$invoice->getId().').'
                    );
                }

                $subject = $recurring->getEmailSubject() ?? SubmitInvoiceEmailAction::defaultModalSubjectFromTemplate();
                $message = $recurring->getEmailText() ?? SubmitInvoiceEmailAction::defaultModalMessageBodyFromTemplateForInvoice($invoice);
                $emailData = SubmitInvoiceEmailAction::applyTemplateVariablesAfterPersist($invoice, [
                    'subject' => $subject,
                    'message' => $message,
                ]);

                app()->makeWith(SendInvoiceMailAction::class, ['invoice' => $invoice])->executeWithModalEmail(
                    $toEmails,
                    $ccEmails,
                    $bccEmails,
                    (string) ($emailData['subject'] ?? ''),
                    (string) ($emailData['message'] ?? ''),
                );
            } else {
                app()->makeWith(SendInvoiceMailAction::class, ['invoice' => $invoice])->execute();
            }

            $invoice->setStatus(OrderGeneralStatus::Sent);
            $invoice->save();

            if (config('exact.enabled')) {
                SyncInvoiceToExactJob::dispatch($invoice->getId(), Auth::id());
            }

            $recurring->setLastIssuedAt(now());
            $recurring->setNextRunDate(RecurringInvoiceSchedule::advanceNextRunDate(
                $recurring->getNextRunDate(),
                $recurring->getStartDay(),
                $recurring->getFrequency(),
            ));
            $recurring->save();
        });
    }

    private function recurringUsesStoredEmailTemplate(RecurringInvoice $recurring): bool
    {
        return $recurring->getEmailSubject() !== null
            || $recurring->getEmailText() !== null
            || $recurring->getEmailCcKeys() !== []
            || $recurring->getEmailBccKeys() !== [];
    }

    public function executeSafe(RecurringInvoice $recurring): bool
    {
        if ($this->deactivateIfNoLines($recurring)) {
            return false;
        }

        try {
            $this->execute($recurring);

            return true;
        } catch (Throwable $e) {
            report($e);
            Log::warning('recurring_invoice.issue_failed', [
                'recurring_invoice_id' => $recurring->getKey(),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function deactivateIfNoLines(RecurringInvoice $recurring): bool
    {
        if ($recurring->lines()->exists()) {
            return false;
        }

        if ($recurring->getIsActive()) {
            $recurring->setIsActive(false);
            $recurring->save();

            Log::warning('recurring_invoice.deactivated_no_lines', [
                'recurring_invoice_id' => $recurring->getKey(),
            ]);
        }

        return true;
    }
}
