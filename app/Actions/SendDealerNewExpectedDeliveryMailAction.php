<?php

namespace App\Actions;

use App\Mail\DealerNewExpectedDeliveryMail;
use App\Models\PurchaseOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendDealerNewExpectedDeliveryMailAction extends TransactionalAction
{
    public function __construct(
        private readonly PurchaseOrder $purchaseOrder,
        private readonly string $expectedDeliveryDate,
        private readonly string $email,
    ) {}

    public function getKey(): array
    {
        return ['dealer_new_expected_delivery_mail_po', $this->purchaseOrder->getId(), $this->expectedDeliveryDate];
    }

    public function verifyTime(): Carbon
    {
        return $this->purchaseOrder->getCreatedAt() ?? now();
    }

    public function validate(): bool|string
    {
        return $this->purchaseOrder->getId() > 0 && $this->email !== '';
    }

    public function run(mixed ...$params): mixed
    {
        Mail::to($this->email)
            ->send(new DealerNewExpectedDeliveryMail($this->purchaseOrder, $this->expectedDeliveryDate));

        return true;
    }
}
