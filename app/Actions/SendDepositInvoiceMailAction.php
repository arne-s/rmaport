<?php

namespace App\Actions;

use App\Enums\OrderGeneralStatus;
use App\Mail\DepositInvoiceMail;
use App\Models\Order\BaseOrder;
use App\Models\Order\DepositInvoice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendDepositInvoiceMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    /**
     * @throws Throwable
     */
    public function execute(BaseOrder $order): void
    {
        $deposit = $order->depositInvoice;
        if ($deposit instanceof DepositInvoice && $deposit->getSentAt() !== null) {
            return;
        }

        $resolved = SendInvoiceMailAction::buildInvoiceMailToCcArrays($order);

        if ($resolved['to'] === []) {
            Log::warning('SendDepositInvoiceMailAction: geen ontvangers voor aanbetalingsfactuur-mail', [
                'order_id' => $order->getId(),
                'customer_id' => $order->getCustomerId(),
                'billing_customer_id' => $order->billing_customer_id,
            ]);

            return;
        }

        $to = $resolved['to'];
        $cc = $resolved['cc'];

        if ($deposit instanceof DepositInvoice) {
            $deposit->getOrCreatePublicDownloadUuid();
        }

        Mail::send(new DepositInvoiceMail($order));

        if ($deposit instanceof DepositInvoice) {
            $deposit->setSentAt(now());
            if ($deposit->getStatus() === OrderGeneralStatus::Pending) {
                $deposit->setStatus(OrderGeneralStatus::Sent);
            }
            $deposit->saveQuietly();
        }

        $this->logger->logSent($order, DepositInvoiceMail::class, $to, $cc);
    }
}
