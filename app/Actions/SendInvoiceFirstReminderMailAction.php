<?php

namespace App\Actions;

use App\Mail\InvoiceFirstReminderMail;
use App\Models\Order\Invoice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

class SendInvoiceFirstReminderMailAction
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
            Log::warning('SendInvoiceFirstReminderMailAction: geen ontvangers voor 1e betalingsherinnering', [
                'invoice_id' => $this->invoice->getId(),
                'customer_id' => $this->invoice->getCustomerId(),
                'billing_customer_id' => $this->invoice->billing_customer_id,
            ]);
            throw new InvalidArgumentException(
                'Geen e-mailontvanger voor 1e betalingsherinnering (invoice id ' . $this->invoice->getId() . ').'
            );
        }

        $this->invoice->getOrCreatePublicDownloadUuid();

        $mailable = new InvoiceFirstReminderMail($this->invoice);
        $subject = $mailable->getTemplateSubject();

        Mail::send($mailable);

        app(OrderMailEventLogger::class)->logSent(
            $this->invoice,
            InvoiceFirstReminderMail::class,
            $resolved['to'],
            $resolved['cc'],
            subject: $subject,
        );

        $this->invoice->setFirstReminderSentAt(now());
        $this->invoice->saveQuietly();
    }
}
