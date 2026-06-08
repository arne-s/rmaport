<?php

namespace App\Mail;

use App\Mail\Concerns\BuildsQuotePdfDownloadButton;
use App\Mail\Traits\HasTemplate;
use App\Models\Customer;
use App\Models\Order\Quote;
use App\Models\QuoteApproval;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Route;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class QuoteApprovedMail extends Mailable
{
    use BuildsQuotePdfDownloadButton;
    use HasTemplate;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Quote $quote,
        public QuoteApproval $approval,
        public bool $forEmailPreview = false,
    ) {}

    /**
     * Recipients from the e-mail template in admin are not used; placeholders follow the {@see Customer} on the quote.
     *
     * @return array<string, string>
     */
    public function getTemplateRecipientVars(): array
    {
        $customer = $this->resolveCustomerForMail();

        if ($customer === null) {
            return [];
        }

        return [
            'user_name' => $customer->getName(),
            'user_first_name' => $customer->first_name ?? '',
            'user_last_name' => $customer->last_name ?? '',
            'user_email' => (string) ($customer->getEmail() ?? ''),
        ];
    }

    /**
     * Template TO/CC/BCC in admin must not change who receives this mail.
     */
    public function allowOverrideTo(): bool
    {
        return false;
    }

    /**
     * Extra template placeholders (merged after {@see self::getTemplateRecipientVars()}).
     *
     * [quote_download_url] is the absolute URL to approve-quote.pdf for this approval (quote subdomain when QUOTE_DOMAIN is set).
     *
     * @return array<string, string>
     */
    public function getTemplateVars(): array
    {
        $this->quote->loadMissing(['customer', 'billingCustomer', 'main']);

        $customerName = $this->quote->customer?->getName()
            ?? $this->quote->billingCustomer?->getName()
            ?? trim((string) ($this->approval->customer_name ?? ''));
        $firstName = $this->quote->customer?->getFirstName()
            ?? $this->quote->billingCustomer?->getFirstName()
            ?? '';

        $mainId = $this->quote->getMain()?->getId() ?? $this->quote->main_id;

        $orderLink = $mainId !== null
            ? route('filament.app.resources.mains.view', ['record' => $mainId], absolute: true)
            : '';

        $quoteDownloadUrl = $this->resolveQuoteDownloadUrl();

        return [
            'customer_name' => trim((string) ($this->approval->customer_name ?? '')) ?: $customerName,
            'customer_first_name' => $customerName,
            'first_name' => $firstName,
            'quote_number' => $this->quote->getUidFormatted(),
            'main_number' => $this->quote->main?->getUidFormatted() ?? '',
            'order_link' => $orderLink,
            'quote_download_url' => $quoteDownloadUrl,
            'quote_download_button' => $this->absoluteDownloadButton($quoteDownloadUrl, 'Offerte downloaden'),
        ];
    }

    private function resolveQuoteDownloadUrl(): string
    {
        if (Route::has('approve-quote.pdf')) {
            return route('approve-quote.pdf', ['uuid' => $this->approval->getUuid()], absolute: true);
        }

        if ($this->forEmailPreview) {
            return $this->buildQuotePdfAbsoluteUrlForPreview();
        }

        return '';
    }

    /**
     * When quote routes are not registered (e.g. empty QUOTE_DOMAIN), still show a plausible PDF URL in mail preview.
     */
    private function buildQuotePdfAbsoluteUrlForPreview(): string
    {
        $uuid = $this->approval->getUuid();
        $host = config('quote.domain');
        if (! is_string($host) || $host === '') {
            $parsedHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            $host = is_string($parsedHost) && $parsedHost !== '' ? $parsedHost : 'localhost';
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);
        $scheme = ($scheme === 'http' || $scheme === 'https') ? $scheme : 'https';

        return $scheme.'://'.$host.'/'.$uuid.'/offerte.pdf';
    }

    /**
     * @throws Throwable
     */
    public static function preview(): static
    {
        $quote = Quote::resolveForEmailPreview();
        $approval = $quote->resolvePublicQuoteApproval();

        if (! $approval instanceof QuoteApproval) {
            throw new RuntimeException('No quote approval row found for preview.');
        }

        return new static($quote, $approval, forEmailPreview: true);
    }

    public function build(): self
    {
        $customer = $this->resolveCustomerForMail();
        if ($customer === null) {
            throw new RuntimeException('QuoteApprovedMail requires an associated customer on the quote.');
        }

        $email = $customer->getEmail();
        if (! $this->isValidTemplateRecipientEmail($email)) {
            throw new RuntimeException('QuoteApprovedMail requires a valid customer e-mail address.');
        }

        return $this
            ->view('emails.template-content', [
                'content' => $this->getTemplateContent(),
            ])
            ->subject($this->getTemplateSubject())
            ->to($email, $customer->getName());
    }

    private function resolveCustomerForMail(): ?Customer
    {
        $this->quote->loadMissing('customer');

        return $this->quote->customer;
    }
}
