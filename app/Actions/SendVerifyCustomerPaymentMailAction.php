<?php

namespace App\Actions;

use App\Mail\Unit\VerifyCustomerPaymentMail;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendVerifyCustomerPaymentMailAction
{
    public function __construct(protected OrderMailEventLogger $logger) {}

    public function execute(Main $main): bool
    {
        if (! VerifyCustomerPaymentMail::hasConfiguredRecipients()) {
            Log::warning('VerifyCustomerPaymentMail skipped: template has no deliverable recipients.', [
                'main_id' => $main->getKey(),
            ]);

            return false;
        }

        Mail::send(new VerifyCustomerPaymentMail($main));

        $this->logger->logSent($main, VerifyCustomerPaymentMail::class, []);

        return true;
    }
}
