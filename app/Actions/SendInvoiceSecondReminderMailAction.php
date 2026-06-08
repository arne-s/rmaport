<?php

namespace App\Actions;

use App\Mail\InvoiceSecondReminderMail;
use App\Models\Order\Invoice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

class SendInvoiceSecondReminderMailAction
{
    public function __construct(
        protected Invoice $invoice,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(): void
    {
        $resolved = SendInvoiceMailAction::buildInvoiceMailToCcArrays($this->invoice);

        if ($resolved['to'] === []) {
            Log::warning('SendInvoiceSecondReminderMailAction: geen ontvangers voor 2e betalingsherinnering', [
                'invoice_id' => $this->invoice->getId(),
                'customer_id' => $this->invoice->getCustomerId(),
                'billing_customer_id' => $this->invoice->billing_customer_id,
            ]);
            throw new InvalidArgumentException(
                'Geen e-mailontvanger voor 2e betalingsherinnering (invoice id ' . $this->invoice->getId() . ').'
            );
        }

        $this->invoice->getOrCreatePublicDownloadUuid();

        $mailable = new InvoiceSecondReminderMail($this->invoice);
        $subject = $mailable->getTemplateSubject();

        Mail::send($mailable);

        app(OrderMailEventLogger::class)->logSent(
            $this->invoice,
            InvoiceSecondReminderMail::class,
            $resolved['to'],
            $resolved['cc'],
            subject: $subject,
        );

        $this->invoice->setSecondReminderSentAt(now());
        $this->invoice->saveQuietly();
    }
}
