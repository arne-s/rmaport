<?php

namespace App\Mail;

use App\Helpers\EmailHelper;
use App\Mail\Concerns\BuildsQuotePdfDownloadButton;
use App\Mail\Traits\HasTemplate;
use App\Models\Order\CreditInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CreditInvoiceMail extends Mailable
{
    use BuildsQuotePdfDownloadButton;
    use HasTemplate;
    use Queueable;
    use SerializesModels;

    public CreditInvoice $invoice;
    public ?string $subjectOverride = null;
    public ?string $messageOverride = null;

    public function __construct(
        CreditInvoice $invoice,
        ?string $subjectOverride = null,
        ?string $messageOverride = null,
    ) {
        $this->invoice = $invoice;
        $this->subjectOverride = $subjectOverride;
        $this->messageOverride = $messageOverride;
    }

    public function allowOverrideTo(): bool
    {
        return false;
    }

    /** @return array<string, string> */
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
            'invoice_number' => $this->invoice->getUidFormatted() ?? '',
            'original_invoice_number' => $this->invoice->invoice?->getUidFormatted() ?? '',
            'credit_invoice_number' => $this->invoice->getUidFormatted() ?? '',
            'invoice_download_button' => $this->quotePdfDownloadButton(
                'quote.public.invoice-pdf',
                (string) ($this->invoice->public_download_uuid ?? ''),
                'Creditfactuur downloaden',
            ),
        ];
    }

    public static function getRawTemplateContentFromDatabase(): string
    {
        $template = \App\Models\EmailTemplate::query()->where('class', self::class)->first();
        $content = $template?->getContent();

        if (is_string($content) && trim($content) !== '') {
            return $content;
        }

        return '';
    }

    public static function getRawTemplateSubjectFromDatabase(): string
    {
        $template = \App\Models\EmailTemplate::query()->where('class', self::class)->first();
        $subject = $template?->getSubject();

        return is_string($subject) ? $subject : '';
    }

    public function interpolatePlaceholders(string $str): string
    {
        return $this->parseContent($str);
    }

    public static function preview(): static
    {
        $invoice = CreditInvoice::query()->latest()->first();

        if ($invoice instanceof CreditInvoice) {
            $invoice->getOrCreatePublicDownloadUuid();
        }

        return new static($invoice ?? new CreditInvoice());
    }

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

        $customer = $this->invoice->customer;
        $primaryToEmail = null;
        if ($customer !== null) {
            $primaryToEmail = $customer->getEmail();
            if (EmailHelper::isValid($primaryToEmail)) {
                $mail->to($primaryToEmail, $customer->getName() ?? '');
            }
        }

        $company = $this->invoice->billingCustomer;
        if ($company !== null) {
            $companyEmail = $company->getEmail();
            if (
                EmailHelper::isValid($companyEmail)
                && ! EmailHelper::billingCcDuplicatesPrimaryRecipient($customer, $company, $primaryToEmail)
            ) {
                $mail->cc($companyEmail, $company->getName() ?? '');
            }
        }

        $this->applyTemplateRecipients();

        return $mail;
    }
}
