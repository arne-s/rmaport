<?php

namespace App\Mail;

use App\Actions\SendInvoiceMailAction;
use App\Enums\OrderType;
use App\Mail\Concerns\BuildsQuotePdfDownloadButton;
use App\Mail\Traits\HasTemplate;
use App\Models\EmailTemplate;
use App\Models\Order\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * CMS-driven mail for standalone invoices sent from Filament ({@see \App\Filament\Resources\InvoiceResource\Pages\EditInvoice}).
 */
class CustomInvoiceMail extends Mailable
{
    use BuildsQuotePdfDownloadButton;
    use HasTemplate;
    use Queueable;
    use SerializesModels;

    public Invoice $invoice;

    public ?string $subjectOverride = null;

    public ?string $messageOverride = null;

    public bool $recipientsResolvedByMailFacade = false;

    /**
     * @throws Throwable
     */
    public function __construct(
        Invoice $invoice,
        ?string $subjectOverride = null,
        ?string $messageOverride = null,
        bool $recipientsResolvedByMailFacade = false,
    ) {
        $this->invoice = $invoice;
        $this->subjectOverride = $subjectOverride;
        $this->messageOverride = $messageOverride;
        $this->recipientsResolvedByMailFacade = $recipientsResolvedByMailFacade;
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
        $this->invoice->loadMissing(['paymentLink', 'customer', 'billingCustomer', 'main', 'order.depositInvoice']);

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

    public static function getRawTemplateContentFromDatabase(): string
    {
        $template = EmailTemplate::query()->where('class', self::class)->first();
        $content = $template?->getContent();

        if (is_string($content) && trim($content) !== '') {
            return $content;
        }

        return '';
    }

    public static function getRawTemplateSubjectFromDatabase(): string
    {
        $template = EmailTemplate::query()->where('class', self::class)->first();
        $subject = $template?->getSubject();

        return is_string($subject) ? $subject : '';
    }

    /**
     * @throws Throwable
     */
    public function interpolatePlaceholders(string $str): string
    {
        return $this->parseContent($str);
    }

    public static function preview(): static
    {
        $invoice = Invoice::withoutGlobalScopes()
            ->where('type', OrderType::Invoice->value)
            ->whereNull('main_id')
            ->whereNull('order_id')
            ->whereNotNull('uid')
            ->with(['customer', 'billingCustomer'])
            ->latest()
            ->first();

        if (! $invoice instanceof Invoice) {
            $invoice = Invoice::query()
                ->whereNotNull('uid')
                ->with(['customer', 'billingCustomer', 'main', 'order.depositInvoice'])
                ->latest()
                ->first();
        }

        if (! $invoice instanceof Invoice) {
            $invoice = Invoice::query()
                ->with(['customer', 'billingCustomer'])
                ->where(function ($query): void {
                    $query
                        ->whereNotNull('customer_id')
                        ->orWhereNotNull('billing_customer_id');
                })
                ->latest()
                ->first();
        }

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
        $content = $this->messageOverride !== null && $this->messageOverride !== ''
            ? $this->interpolatePlaceholders($this->messageOverride)
            : $this->getTemplateContent();
        $subject = $this->subjectOverride !== null && $this->subjectOverride !== ''
            ? $this->interpolatePlaceholders($this->subjectOverride)
            : $this->getTemplateSubject();

        $mail = $this
            ->view('emails.template-content', [
                'content' => $content,
            ])
            ->subject($subject);

        if (! $this->recipientsResolvedByMailFacade) {
            SendInvoiceMailAction::applyInvoiceMailToCcToMailable($mail, $this->invoice);
        }

        $this->applyTemplateRecipients();

        return $mail;
    }
}
