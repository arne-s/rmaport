<?php

namespace App\Actions;

use App\Models\Order\BaseOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderReadyForQuoteMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(BaseOrder $order): void
    {
        $mailClass = \App\Mail\Unit\OrderReadyForQuoteMail::class;
        $mailable = new $mailClass($order);

        if (method_exists($mailable, 'resolveRecipient')) {
            [$toEmail] = $mailable->resolveRecipient();
        } else {
            $toEmail = $order->billingCustomer?->getEmail() ?? $order->customer?->getEmail();
        }

        if (! $toEmail) {
            Log::warning($mailClass . ': geen e-mailadres gevonden, mail niet verstuurd.', [
                'order_id' => $order->getId(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent($order, $mailClass, []);
        } catch (\Throwable $e) {
            Log::error($mailClass . ': verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
            ]);
        }
    }
}
