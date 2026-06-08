<?php

namespace App\Mail\Service;

use App\Enums\OrderGeneralStatus;
use App\Mail\Traits\HasTemplate;
use App\Models\Order\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class QuoteMail extends Mailable
{
    use HasTemplate, Queueable, SerializesModels;

    public Quote $quote;
    public ?string $subjectOverride = null;
    public ?string $messageOverride = null;

    /**
     * @var array<int, array{path: string, name: string, mime: string}>
     */
    private array $attachmentFiles = [];

    private ?string $primaryRecipientKey = null;

    /**
     * @throws Throwable
     */
    public function __construct(
        Quote $quote,
        ?string $subjectOverride = null,
        ?string $messageOverride = null,
        array $attachments = [],
        ?string $primaryRecipientKey = null,
    ) {
        $this->quote = $quote;
        $this->subjectOverride = $subjectOverride;
        $this->messageOverride = $messageOverride;
        $this->attachmentFiles = $attachments;
        $this->primaryRecipientKey = $primaryRecipientKey;
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
        $company = $this->quote->billingCustomer;
        $virtualCustomer = $this->quote->main?->getVirtualCustomer($this->primaryRecipientKey)
            ?? match ($this->primaryRecipientKey) {
                'dealer' => $company,
                default => $this->quote->customer ?? $company,
            };

        $firstName = $virtualCustomer?->getFirstName() ?? '';
        $lastName = $virtualCustomer?->getLastName() ?? '';

        $formattedUid = $this->quote->getUidFormatted() ?: (string) ($this->quote->uid ?? '');
        $orderNumber = (string) ($this->quote->getUid() ?? '');

        return [
            'reference' => (string) ($this->quote->uid ?? ''),
            'quote_number' => $formattedUid,
            'order_number' => $orderNumber !== '' ? $orderNumber : '(pending)',
            'customer_name' => $company?->getName() ?? '',
            'customer_number' => (string) ($company?->debtor_number ?? ''),
            'customer_email' => $virtualCustomer?->getEmail() ?? '',
            'first_name' => $firstName,
            'customer_first_name' => $firstName,
            'customer_last_name' => $lastName,
            'quote_download_button' => $this->quote->getPublicApprovalButtonHtml(),
            'quote_direct_download_button' => $this->quote->getPublicDirectDownloadButtonHtml(),
        ];
    }

    public static function preview(): static
    {
        $candidates = Quote::query()
            ->where('status', '!=', OrderGeneralStatus::Draft)
            ->latest('id')
            ->limit(50);

        foreach ($candidates->cursor() as $quote) {
            if ($quote->currentPendingQuoteApproval() !== null) {
                return new static($quote);
            }

            if (! $quote->quoteApproval()->exists()) {
                $quote->quoteApproval()->create([
                    'uuid' => (string) Str::uuid(),
                    'customer_name' => '',
                ]);
                $quote->load('quoteApproval');

                return new static($quote);
            }
        }

        $quote = Quote::query()->latest('id')->first();

        if ($quote === null) {
            throw new RuntimeException('No quote found for QuoteMail::preview().');
        }

        return new static($quote);
    }

    public function build(): self
    {
        $this->applyTemplateRecipients();

        $content = $this->messageOverride !== null && $this->messageOverride !== ''
            ? $this->parseTemplateString($this->messageOverride)
            : $this->getTemplateContent();
        $subject = $this->subjectOverride !== null && $this->subjectOverride !== ''
            ? $this->parseTemplateString($this->subjectOverride)
            : $this->getTemplateSubject();

        $mail = $this
            ->view('emails.template-content', [
                'content' => $content,
            ])
            ->subject($subject);

        foreach ($this->attachmentFiles as $attachment) {
            $mail->attach($attachment['path'], [
                'as' => $attachment['name'],
                'mime' => $attachment['mime'],
            ]);
        }

        return $mail;
    }
}
