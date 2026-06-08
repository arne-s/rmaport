<?php

namespace App\Actions;

use App\Mail\Unit\InvoicePaymentNotificationMail;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendInvoicePaymentNotificationMailAction
{
    public function __construct(protected OrderMailEventLogger $logger) {}

    public function execute(Main $main): bool
    {
        if (! InvoicePaymentNotificationMail::hasConfiguredRecipients()) {
            Log::warning('InvoicePaymentNotificationMail skipped: template has no deliverable recipients.', [
                'main_id' => $main->getKey(),
            ]);

            return false;
        }

        Mail::send(new InvoicePaymentNotificationMail($main));

        $this->logger->logSent($main, InvoicePaymentNotificationMail::class, []);

        return true;
    }
}
