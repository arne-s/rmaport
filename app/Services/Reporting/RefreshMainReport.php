<?php

namespace App\Services\Reporting;

use App\Enums\AppointmentType;
use App\Enums\OrderSubtype;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ProductType;
use App\Models\MainReport;
use App\Models\Order\Main;
use App\Models\Order\Quote;
use App\Models\OrderProduct;
use App\Models\OrderStatusChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class RefreshMainReport
{
    private const MAIN_CHUNK_SIZE = 100;

    /**
     * Rebuild {@link MainReport} rows for all mains, or for a single {@link Main} id.
     */
    public function refresh(?int $mainId = null): void
    {
        $query = Main::query()
            ->with([
                'serialNumber',
                'customer',
                'billingCustomer',
                'advisor',
                'quotes.orderProducts.purchaseOrder',
                'quotes.orderProducts.supplier',
                'quotes.orderProducts.product',
                'orders.orderProducts.purchaseOrder',
                'orders.orderProducts.supplier',
                'orders.orderProducts.product',
                'statusChanges',
                'appointments',
                'children',
            ])
            ->orderBy('id');

        if ($mainId !== null) {
            $query->whereKey($mainId);
        }

        $query->chunkById(self::MAIN_CHUNK_SIZE, function (Collection $mains): void {
            foreach ($mains as $main) {
                /** @var Main $main */
                MainReport::query()->updateOrCreate(
                    ['main_id' => $main->getId()],
                    $this->buildAttributes($main),
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAttributes(Main $main): array
    {
        $approvedQuote = $this->approvedQuote($main);
        $orderLines = $this->orderChildProducts($main);
        $quoteLines = $approvedQuote !== null
            ? $approvedQuote->orderProducts
            : collect();

        $purchaseFrame = $this->purchaseLinesTotal($orderLines, $quoteLines, ProductType::Frame);
        $purchaseParts = $this->purchaseLinesTotal($orderLines, $quoteLines, ProductType::Part);

        $sale = $approvedQuote !== null
            ? round((float) $approvedQuote->getCompanySalesPriceTotal(), 2)
            : null;

        $margin = $sale !== null
            ? round($sale - $purchaseFrame - $purchaseParts, 2)
            : null;

        $frameLine = $this->canonicalFrameLine($orderLines, $quoteLines);
        $po = $frameLine?->purchaseOrder;

        $framePoCarbon = null;
        if ($po !== null) {
            $framePoCarbon = $po->sent_at ?? $po->created_at;
            if ($framePoCarbon !== null) {
                $framePoCarbon = Carbon::parse($framePoCarbon)->startOfDay();
            }
        }

        $fittingAt = $main->appointments
            ->filter(static fn ($a): bool => $a->type === AppointmentType::Fitting)
            ->sortByDesc(static fn ($a) => $a->datetime)
            ->first()
            ?->datetime;

        $billingRecipient = $main->getBillingRecipient();
        $invoiceUser = $billingRecipient[1] ?? '';
        $invoiceUser = $invoiceUser !== '' ? $invoiceUser : null;

        $orderSentAt = $this->resolveOrderSentAt($main);

        $slotInvoice = $main->children
            ->filter(static fn ($child): bool => $child->type === OrderType::Invoice)
            ->sortByDesc(static fn ($child) => $child->rev)
            ->first();

        return [
            'customer_id' => $main->customer_id,
            'customer_debtor_number' => $this->trimOrNull($main->customer?->debtor_number),
            'billing_customer_id' => $main->billing_customer_id,
            'billing_customer_debtor_number' => $this->trimOrNull($main->billingCustomer?->debtor_number),
            'customer_name' => $this->trimOrNull($main->getCustomerAddressDisplayName()),
            'dealer_name' => $this->resolveDealerName($main),
            'order_uid' => $this->trimOrNull($main->getUid()),
            'subtype' => $main->getSubtype()?->value,
            'main_created_at' => $main->created_at,
            'chair_type' => $this->scalarToTrimmedString($frameLine?->product?->getChairType()),
            'supplier_name' => $this->trimOrNull($frameLine?->supplier?->name),
            'serial_number' => $this->trimOrNull($this->resolveSerialNumberForMain($main)),
            'advisor_name' => $this->trimOrNull($main->advisor?->getName()),
            'sale_price_total' => $sale,
            'purchase_price_frame' => $purchaseFrame > 0 ? $purchaseFrame : null,
            'purchase_price_parts' => $purchaseParts > 0 ? $purchaseParts : null,
            'margin_price' => $margin,
            'invoice_user' => $invoiceUser,
            'frame_purchase_order_at' => $framePoCarbon?->toDateString(),
            'frame_purchase_order_month' => $framePoCarbon?->month,
            'frame_purchase_order_year' => $framePoCarbon?->year,
            'frame_purchase_order_month_year' => $framePoCarbon !== null
                ? $framePoCarbon->format('n-Y')
                : null,
            'fitting_appointment_at' => $fittingAt,
            'quote_sent_at' => $approvedQuote?->sent_at,
            'quote_approved_at' => $this->firstMainStatusChangeAt($main, OrderStatus::OrderDraft),
            'order_sent_at' => $orderSentAt,
            'ready_for_pickup_at' => $this->firstMainStatusChangeAt($main, OrderStatus::ReadyForPickup),
            'delivered_at' => $this->firstMainStatusChangeAt($main, OrderStatus::Delivered),
            'invoice_sent_at' => $slotInvoice?->sent_at,
        ];
    }

    private function approvedQuote(Main $main): ?Quote
    {
        return $main->quotes->first(
            static fn ($q): bool => $q->status === OrderGeneralStatus::Completed,
        );
    }

    /**
     * @return Collection<int, OrderProduct>
     */
    private function orderChildProducts(Main $main): Collection
    {
        return $main->orders->flatMap(static fn ($order) => $order->orderProducts);
    }

    /**
     * @param Collection<int, OrderProduct> $orderLines
     * @param Collection<int, OrderProduct> $quoteLines
     */
    private function purchaseLinesTotal(
        Collection $orderLines,
        Collection $quoteLines,
        ProductType $type,
    ): float {
        $fromOrders = $orderLines->filter(
            static fn (OrderProduct $op): bool => $op->getType() === $type
                && $op->purchase_order_id !== null,
        );

        $lines = $fromOrders;
        if ($lines->isEmpty()) {
            $lines = $quoteLines->filter(
                static fn (OrderProduct $op): bool => $op->getType() === $type
                    && $op->purchase_order_id !== null,
            );
        }

        $sum = $lines->sum(static fn (OrderProduct $op): float => (float) $op->company_purchase_price_total);

        return round($sum, 2);
    }

    /**
     * @param Collection<int, OrderProduct> $orderLines
     * @param Collection<int, OrderProduct> $quoteLines
     */
    private function canonicalFrameLine(Collection $orderLines, Collection $quoteLines): ?OrderProduct
    {
        $frames = $this->frameProducts($orderLines);
        if ($frames->isEmpty()) {
            $frames = $this->frameProducts($quoteLines);
        }

        return $frames
            ->sortBy(static fn (OrderProduct $op): array => [
                $op->purchase_order_id !== null ? 0 : 1,
                $op->id,
            ])
            ->first();
    }

    /**
     * @param Collection<int, OrderProduct> $lines
     * @return Collection<int, OrderProduct>
     */
    private function frameProducts(Collection $lines): Collection
    {
        return $lines->filter(
            static fn (OrderProduct $op): bool => $op->getType() === ProductType::Frame,
        );
    }

    private function resolveDealerName(Main $main): string
    {
        $name = trim((string) ($main->billingCustomer?->getName() ?? ''));

        return $name !== '' ? $name : 'Particulier';
    }

    private function resolveOrderSentAt(Main $main): ?Carbon
    {
        $dates = $main->orders
            ->reject(static fn ($order): bool => in_array(
                $order->getStatus(),
                [OrderGeneralStatus::Initial, OrderGeneralStatus::Draft],
                true,
            ))
            ->pluck('sent_at')
            ->filter();

        if ($dates->isEmpty()) {
            return null;
        }

        return $dates->min();
    }

    private function firstMainStatusChangeAt(Main $main, OrderStatus $toStatus): ?Carbon
    {
        /** @var OrderStatusChange|null $row */
        $row = $main->statusChanges
            ->filter(static fn (OrderStatusChange $c): bool => $c->order_product_id === null
                && $c->to_status === $toStatus->value)
            ->sortBy(static fn (OrderStatusChange $c) => $c->created_at)
            ->first();

        return $row?->created_at;
    }

    private function trimOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function scalarToTrimmedString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) || is_numeric($value)) {
            return $this->trimOrNull((string) $value);
        }

        return null;
    }

    private function resolveSerialNumberForMain(Main $main): ?string
    {
        if (in_array($main->getSubtype(), [OrderSubtype::Part, OrderSubtype::Service], true)) {
            $linked = trim((string) data_get($main->getFittingNote(), 'linked_serial_number', ''));

            if ($linked !== '') {
                return $linked;
            }
        }

        return $main->getSerialNumberRecord()?->getSerialNumber();
    }
}
