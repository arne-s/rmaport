<?php

namespace App\Actions;

use App\Mail\Unit\OrderCheckAdministrationMail;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Mail;

class SendOrderCheckAdministrationMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(BaseOrder $order): void
    {
        $main = $order instanceof Main ? $order : $order->getMain();

        if (! $main instanceof Main || ! $main->billingCustomerReceivesOrWillReceiveDepositInvoice()) {
            return;
        }

        Mail::send(new OrderCheckAdministrationMail($order));
        $this->logger->logSent($order, OrderCheckAdministrationMail::class, []);
    }
}
