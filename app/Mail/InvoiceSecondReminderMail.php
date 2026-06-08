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
use RuntimeException;
use Throwable;

/**
 * Tweede betalingsherinnering slot- of aanbetalingsfactuur (DB template: {@see self::class}).
 */
class InvoiceSecondReminderMail extends Mailable
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
        $customerName = $this->invoice->customer?->getName()
            ?? $this->invoice->billingCustomer?->getName()
            ?? '';
        $firstName = $this->invoice->customer?->getFirstName()
            ?? $this->invoice->billingCustomer?->getFirstName()
            ?? '';

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

    public function interpolatePlaceholders(string $str): string
    {
        return $this->parseContent($str);
    }

    public static function preview(): static
    {
        $invoice = Invoice::withoutGlobalScopes()
            ->where('type', OrderType::Invoice)
            ->whereHas('main')
            ->latest('id')
            ->first();

        if (! $invoice instanceof Invoice) {
            throw new RuntimeException('No slot invoice with main found for second reminder mail preview.');
        }

        $invoice->getOrCreatePublicDownloadUuid();

        return new self($invoice);
    }

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
