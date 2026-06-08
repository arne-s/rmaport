<?php

namespace App\Models\Order;

use App\Enums\PaymentTerms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Invoice extends BaseOrder
{
    protected $table = 'orders';

    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope('type',
            fn(Builder $builder) => $builder->whereIn('type', ['invoice', 'deposit_invoice']));
    }

    public function setInitialPaymentAmount(): Invoice
    {
        $order = $this->order;
        if (! $order instanceof Order) {
            $total = $this->getCompanySalesPriceTotalIncVat();
            $this->setDepositAmount(0);
            $this->setPaymentAmount($total);
            $this->setPaymentPercentage(100);

            return $this;
        }

        $terms = PaymentTerms::tryFrom($order->getPaymentTermsValueForBillingContext());

        if ($terms === null || ! PaymentTerms::requiresDepositInvoice($terms)) {
            $total = $this->getCompanySalesPriceTotalIncVat();
            $this->setDepositAmount(0);
            $this->setPaymentAmount($total);
            $this->setPaymentPercentage(100);

            return $this;
        }

        $mainId = $order->main_id;
        $mainDeposit = null;
        if ($mainId !== null) {
            $mainDeposit = DepositInvoice::query()
                ->where('main_id', $mainId)
                ->orderByDesc('id')
                ->first();
        }

        if ($mainDeposit === null) {
            $total = $this->getCompanySalesPriceTotalIncVat();
            $this->setDepositAmount(0);
            $this->setPaymentAmount($total);
            $this->setPaymentPercentage(100);

            return $this;
        }

        $deposit = $mainDeposit->getDepositAmount();
        $remaining = $this->getCompanySalesPriceTotalIncVat() - $deposit;
        $depositPct = (float) ($mainDeposit->getPaymentPercentage() ?? DepositInvoice::DEFAULT_DEPOSIT_PERCENTAGE);
        $slotPercentage = max(0.0, min(100.0, 100 - $depositPct));

        $this->setDepositAmount($deposit);
        $this->setPaymentAmount($remaining);
        $this->setPaymentPercentage($slotPercentage);

        return $this;
    }

    /**
     * Fully credit the invoice, including all line items.
     */
    public function createFullCreditInvoice(): CreditInvoice
    {
        // Create the credit invoice
        $creditInvoice = $this->createCreditInvoice();

        $credited = 0;
        $orderProducts = $creditInvoice->orderProducts;

        foreach ($orderProducts as $orderProduct) {
            $creditedAmount = $orderProduct->getCompanySalesPriceTotal() * ($creditInvoice->getPaymentPercentage() / 100);
            $credited -= $creditedAmount;
            // Use setter to set the credited amount. credited_amount should be a positive value.
            $orderProduct->setCompanySalesPriceCredited($creditedAmount);
            $orderProduct->setHasCredit(true);
            $orderProduct->save();
        }

        $creditInvoice->setPaymentAmount($credited);
        $creditInvoice->setCompanySalesPriceDiscount(0);
        $creditInvoice->save();

        return $creditInvoice->submitCreditInvoice();
    }

    /**
     * Duplicate the entire order, including all necessary relations.
     *
     * @return self
     */
    public function duplicateOrder(): self
    {
        // Begin a database transaction
        DB::beginTransaction();

        try {
            // Duplicate the main order
            $newOrder = $this->replicate();
            $newOrder->setUid(null); // Reset unique identifiers
            $newOrder->setSentAt(null);
            $newOrder->public_download_uuid = null;
            $newOrder->setCreatedAt(now());
            $newOrder->setUpdatedAt(now());
            $newOrder->save();

            // Duplicate order products
            foreach ($this->orderProducts as $orderProduct) {
                $newOrderProduct = $orderProduct->replicate();
                $newOrderProduct->setOrderId($newOrder->getId());
                $newOrderProduct->save();
            }

            // Duplicate related invoices
            if ($this->invoice) {
                $newInvoice = $this->invoice->replicate();
                $newInvoice->setOrderId($newOrder->getId());
                $newInvoice->save();
            }

            // Duplicate other necessary relations (e.g., payments, discounts, etc.)
            if ($this->payments) {
                foreach ($this->payments as $payment) {
                    $newPayment = $payment->replicate();
                    $newPayment->setOrderId($newOrder->getId());
                    $newPayment->save();
                }
            }

            if ($this->discounts) {
                foreach ($this->discounts as $discount) {
                    $newDiscount = $discount->replicate();
                    $newDiscount->setOrderId($newOrder->getId());
                    $newDiscount->save();
                }
            }

            // Commit the transaction
            DB::commit();

            return $newOrder;
        } catch (\Exception $e) {
            // Rollback the transaction in case of an error
            DB::rollBack();
            throw $e;
        }
    }
}
