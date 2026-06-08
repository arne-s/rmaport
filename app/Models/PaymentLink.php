<?php

namespace App\Models;

use App\Models\Order\BaseOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;

class PaymentLink extends Model
{
    protected $fillable = [
        'mode',
        'description',
        'payment_id',
        'link',
        'paid_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function orderRow(): HasOne
    {
        return $this->hasOne(BaseOrder::class, 'payment_link_id');
    }

    /**
     * Creates a Mollie payment link when applicable and assigns it to the invoice order row.
     */
    public static function createForInvoice(BaseOrder $order): void
    {
        if (! is_string(config('services.mollie.key')) || config('services.mollie.key') === '') {
            return;
        }

        if ($order->getPaymentLinkId() !== null) {
            return;
        }

        if ($order->getPaidAt() !== null) {
            return;
        }

        $amount = (float) ($order->getPaymentAmount() ?? 0.0);
        if ($amount < 0.01) {
            return;
        }

        $invoiceNumber = trim((string) ($order->getUidFormatted() ?? ''));
        $description = $invoiceNumber !== ''
            ? 'Factuur '.$invoiceNumber
            : 'Factuur #'.$order->getId();

        $link = self::createViaMollie($amount, $description);
        if ($link === null) {
            Log::warning('payment_link.create_for_invoice_failed', [
                'order_id' => $order->getId(),
                'type' => $order->getType()?->value,
            ]);

            return;
        }

        $order->setPaymentLinkId($link->id);
        $order->saveQuietly();
    }

    public function setAsPaid(): void
    {
        if ($this->paid_at !== null) {
            return;
        }

        $this->paid_at = now();
        $this->save();
    }

    public static function createViaMollie(float $amount, string $description): ?self
    {
        $apiKey = config('services.mollie.key');
        if (! is_string($apiKey) || $apiKey === '') {
            return null;
        }

        /** @var MollieApiClient $mollie */
        $mollie = app('mollie');

        try {
            $result = $mollie->paymentLinks->create([
                'amount' => [
                    'currency' => 'EUR',
                    'value' => number_format($amount, 2, '.', ''),
                ],
                'expiresAt' => now()->addDays(30)->toIso8601String(),
                'description' => $description,
                'webhookUrl' => route('mollie.webhook'),
            ]);
        } catch (ApiException $e) {
            report($e);

            return null;
        }

        $row = new self;
        $row->payment_id = $result->id;
        $row->mode = $result->mode;
        $row->description = $result->description;
        $row->link = $result->getCheckoutUrl();
        $encoded = json_encode($result);
        $row->meta = is_string($encoded) ? json_decode($encoded, true) : null;
        $row->save();

        return $row;
    }
}
