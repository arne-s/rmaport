<?php

namespace App\Models;

use App\Enums\OrderSubtype;
use App\Models\Order\Main;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Denormalized read model: one row per {@see Main} (aanvraag) for fast reporting.
 *
 * Refreshed by {@see \App\Services\Reporting\RefreshMainReport} / `main-reports:refresh`.
 *
 * @property int $id
 * @property int $main_id
 * @property int|null $customer_id
 * @property string|null $customer_debtor_number Debiteurnummer van de klant.
 * @property int|null $billing_customer_id Factuur-/debiteurklant (Customer id).
 * @property string|null $billing_customer_debtor_number Debiteurnummer van de factuurklant.
 * @property string|null $dealer_name Invoice customer display name ({@see Customer::getName()}) or Particulier.
 * @property string|null $order_uid Main UID (e.g. A-2026-0001).
 * @property OrderSubtype|null $subtype Aanvraagtype (unit, part, service).
 * @property \Illuminate\Support\Carbon|null $main_created_at Aanmaakdatum van de aanvraag (Main created_at).
 * @property string|null $customer_name Display name from Main::getCustomerAddressDisplayName().
 * @property string|null $chair_type From frame product.
 * @property string|null $supplier_name Frame supplier.
 * @property string|null $serial_number
 * @property string|null $advisor_name
 * @property string|null $sale_price_total From approved quote company_sales_price_total.
 * @property string|null $purchase_price_frame
 * @property string|null $purchase_price_parts
 * @property string|null $margin_price
 * @property string|null $invoice_user Billing recipient name from Main::getBillingRecipient().
 * @property \Illuminate\Support\Carbon|null $frame_purchase_order_at
 * @property int|null $frame_purchase_order_month
 * @property int|null $frame_purchase_order_year
 * @property string|null $frame_purchase_order_month_year Format n-Y e.g. 11-2026.
 * @property \Illuminate\Support\Carbon|null $fitting_appointment_at Latest fitting appointment.
 * @property \Illuminate\Support\Carbon|null $quote_sent_at
 * @property \Illuminate\Support\Carbon|null $quote_approved_at First main status change to order_concept (offerte akkoord).
 * @property \Illuminate\Support\Carbon|null $order_sent_at Min sent_at on order rows under main.
 * @property \Illuminate\Support\Carbon|null $ready_for_pickup_at First transition to ReadyForPickup.
 * @property \Illuminate\Support\Carbon|null $delivered_at First transition to Delivered.
 * @property \Illuminate\Support\Carbon|null $invoice_sent_at Latest rev slot invoice sent_at.
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Main $main
 * @property-read Customer|null $billingCustomer
 */
class MainReport extends Model
{
    protected $table = 'main_reports';

    protected $fillable = [
        'main_id',
        'customer_id',
        'customer_debtor_number',
        'billing_customer_id',
        'billing_customer_debtor_number',
        'customer_name',
        'dealer_name',
        'order_uid',
        'subtype',
        'main_created_at',
        'chair_type',
        'supplier_name',
        'serial_number',
        'advisor_name',
        'sale_price_total',
        'purchase_price_frame',
        'purchase_price_parts',
        'margin_price',
        'invoice_user',
        'frame_purchase_order_at',
        'frame_purchase_order_month',
        'frame_purchase_order_year',
        'frame_purchase_order_month_year',
        'fitting_appointment_at',
        'quote_sent_at',
        'quote_approved_at',
        'order_sent_at',
        'ready_for_pickup_at',
        'delivered_at',
        'invoice_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'subtype' => OrderSubtype::class,
            'main_created_at' => 'datetime',
            'sale_price_total' => 'decimal:2',
            'purchase_price_frame' => 'decimal:2',
            'purchase_price_parts' => 'decimal:2',
            'margin_price' => 'decimal:2',
            'frame_purchase_order_at' => 'date',
            'fitting_appointment_at' => 'datetime',
            'quote_sent_at' => 'datetime',
            'quote_approved_at' => 'datetime',
            'order_sent_at' => 'datetime',
            'ready_for_pickup_at' => 'datetime',
            'delivered_at' => 'datetime',
            'invoice_sent_at' => 'datetime',
        ];
    }

    public function main(): BelongsTo
    {
        return $this->belongsTo(Main::class, 'main_id', 'id');
    }

    public function billingCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'billing_customer_id');
    }
}
