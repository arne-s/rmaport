<?php

namespace App\Actions;

use App\Enums\OrderSubtype;
use App\Enums\PaymentTerms;
use App\Mail\Part\OrderReadyForPurchaseMail as PartOrderReadyForPurchaseMail;
use App\Mail\Unit\OrderReadyForPurchaseMail;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

class SendOrderReadyForPurchaseMailAction
{
    public function __construct(protected OrderMailEventLogger $logger) {}

    public function execute(BaseOrder $order): void
    {
        $main = $order instanceof Main ? $order : $order->getMain();

        if (! $main instanceof Main || $main->getPaymentTermsInheritedByChildren() !== PaymentTerms::Advance100) {
            return;
        }

        $mailable = $this->resolveMailable($main, $order);

        Mail::send($mailable);
        $this->logger->logSent($order, $mailable::class, []);
    }

    private function resolveMailable(Main $main, BaseOrder $order): Mailable
    {
        if ($main->getSubtype() === OrderSubtype::Part) {
            return new PartOrderReadyForPurchaseMail($order);
        }

        return new OrderReadyForPurchaseMail($order);
    }
}
