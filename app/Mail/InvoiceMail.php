<?php

namespace App\Mail;

use App\Actions\SendInvoiceMailAction;
use App\Mail\Concerns\BuildsQuotePdfDownloadButton;
use App\Mail\Traits\HasTemplate;
use App\Models\Order\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class InvoiceMail extends Mailable
{
    use BuildsQuotePdfDownloadButton;
    use HasTemplate;
    use Queueable;
    use SerializesModels;

    public Invoice $invoice;

    /**
     * @throws Throwable
     */
    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public function allowOverrideTo(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public function getTemplateVars(): array
    {
        $this->invoice->loadMissing('paymentLink');

        $customerName = $this->invoice->customer?->getName()
            ?? $this->invoice->billingCustomer?->getName()
            ?? '';
        $firstName = $this->invoice->customer?->getFirstName()
            ?? $this->invoice->billingCustomer?->getFirstName()
            ?? '';

        $paymentUrl = $this->invoice->getPaymentLink();

        return [
            'customer_first_name' => $customerName,
            'first_name' => $firstName,
            'main_number' => $this->invoice->main?->getUidFormatted() ?? '',
            'deposit_invoice_number' => $this->invoice->order?->depositInvoice?->getUidFormatted() ?? '',
            'invoice_number' => $this->invoice->getUidFormatted() ?? '',
            'invoice_download_button' => $this->quotePdfDownloadButton(
                'quote.public.invoice-pdf',
                (string) ($this->invoice->public_download_uuid ?? ''),
                'Factuur downloaden',
            ),
            'invoice_payment_link' => $paymentUrl ?? '',
            'invoice_payment_link_button' => $this->invoiceOnlinePaymentButton($paymentUrl),
        ];
    }

    public static function preview(): static
    {
        $invoice = Invoice::query()
            ->whereHas('main')
            ->latest()
            ->first();

        if ($invoice instanceof Invoice) {
            $invoice->getOrCreatePublicDownloadUuid();
        }

        return new static($invoice ?? new Invoice());
    }

    /**
     * @throws Throwable
     */
    public function build(): self
    {
        $mail = $this
            ->view('emails.template-content', [
                'content' => $this->getTemplateContent(),
            ])
            ->subject($this->getTemplateSubject());

        SendInvoiceMailAction::applyInvoiceMailToCcToMailable($mail, $this->invoice);

        $this->applyTemplateRecipients();

        return $mail;
    }
}
