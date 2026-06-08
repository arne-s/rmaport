<?php

namespace App\Actions;

use App\Models\Order\Quote;
use App\Services\MicrosoftMailDispatcher;

class SendQuoteMailAction
{
    public function __construct(
        protected OrderMailEventLogger $logger,
        protected MicrosoftMailDispatcher $dispatcher,
    ) {}

    /**
     * Send the quote e-mail and log an order event on the linked main (when present).
     *
     * @param  array{
     *     to: array<int, string>,
     *     cc?: array<int, string>,
     *     bcc?: array<int, string>,
     *     subject?: string|null,
     *     message?: string|null,
     *     attachments?: array<int, array{path: string, name: string, mime: string}>,
     *     primary_recipient_key?: string|null,
     * }  $data
     */
    public function execute(Quote $quote, array $data): void
    {
        $to  = array_values(array_filter($data['to'] ?? [], fn (mixed $e): bool => is_string($e) && $e !== ''));
        $cc  = array_values(array_filter($data['cc'] ?? [], fn (mixed $e): bool => is_string($e) && $e !== ''));
        $bcc = array_values(array_filter($data['bcc'] ?? [], fn (mixed $e): bool => is_string($e) && $e !== ''));
        $attachments = $data['attachments'] ?? [];

        $primaryRecipientKey = $quote->normalizeQuoteMailPrimaryRecipientKey(
            $data['primary_recipient_key'] ?? null,
        );

        $mailClass = $quote->resolveQuoteMailClass($primaryRecipientKey);

        $mailable = new $mailClass(
            $quote,
            $data['subject'] ?? null,
            $data['message'] ?? null,
            $attachments,
            $primaryRecipientKey,
        );

        $this->dispatcher->dispatch($mailable, $to, $cc, $bcc, $attachments);

        $this->logger->logSent(
            $quote,
            $mailClass,
            $this->logger->normalizeRecipients($to),
            $this->logger->normalizeRecipients($cc),
            $this->logger->normalizeRecipients($bcc),
            is_string($mailable->subject) ? $mailable->subject : null,
        );
    }
}
