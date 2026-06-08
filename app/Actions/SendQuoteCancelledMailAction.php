<?php

namespace App\Actions;

use App\Mail\QuoteCancelledMail;
use App\Models\Order\Quote;
use Illuminate\Support\Facades\Mail;

class SendQuoteCancelledMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Quote $quote): void
    {
        Mail::send(new QuoteCancelledMail($quote));

        [$email, $name] = $quote->getBillingRecipient();
        $to = [];
        if (is_string($email) && $email !== '') {
            $to[] = ['name' => $name, 'email' => $email];
        }

        $this->logger->logSent($quote, QuoteCancelledMail::class, $to);
    }
}

