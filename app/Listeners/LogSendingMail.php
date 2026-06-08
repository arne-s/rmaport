<?php

namespace App\Listeners;

use App\Enums\MailLogStatus;
use App\Models\MailLog;
use App\Models\MailSenderProfile;
use App\Support\MailAddressFormatter;
use App\Support\ResolvesMainIdFromMailData;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

class LogSendingMail
{
    public function __construct()
    {
        //
    }

    public function handle(MessageSending $event): void
    {
        $message = $event->message;

        // Symfony Mailer requires a From header. When using microsoft-graph the
        // transport always sends from the token's address, so we set it here so
        // Symfony doesn't throw before the transport even runs.
        if (! $message->getFrom() && config('mail.default') === 'microsoft-graph') {
            $token = MailSenderProfile::where('is_default', true)->first()?->microsoftMailToken;
            $fromAddress = $token?->microsoft_email ?? config('mail.fallback_from_address');
            $fromName = $token?->microsoft_email
                ? config('app.name', 'Autovision')
                : (config('mail.fallback_from_name') ?? config('app.name', 'Autovision'));

            if ($fromAddress) {
                $message->from($fromAddress, $fromName);
            }
        }

        $mainId = ResolvesMainIdFromMailData::resolve($event->data);

        if ($mainId !== null) {
            $message->getHeaders()->addTextHeader(MailLog::EMAIL_HEADER_MAIN_ID, (string) $mainId);
        }

        $mailLog = MailLog::create([
            'from' => $this->formatAddressField($message, 'From'),
            'to' => $this->formatAddressField($message, 'To'),
            'cc' => $this->formatAddressField($message, 'Cc'),
            'bcc' => $this->formatAddressField($message, 'Bcc'),
            'subject' => $message->getSubject(),
            'body' => $message->getHtmlBody(),
            'headers' => $message->getHeaders()->toString(),
            'status' => MailLogStatus::Sending,
            'attachments' => $this->parseAttachments($message),
            'message_id' => (string) Str::uuid(),
            'is_test' => MailLog::isTestModeEnabled(),
        ]);

        $event->message->getHeaders()->addTextHeader(MailLog::EMAIL_HEADER_MESSAGE_ID, $mailLog->message_id);
    }

    /**
     * Format address strings for sender, to, cc, bcc.
     */
    public function formatAddressField(Email $message, string $field): ?string
    {
        $addresses = match ($field) {
            'From' => $message->getFrom(),
            'To' => $message->getTo(),
            'Cc' => $message->getCc(),
            'Bcc' => $message->getBcc(),
            default => [],
        };

        return MailAddressFormatter::formatAddressList($addresses);
    }

    /**
     * Collect all attachments and format them as strings.
     */
    protected function parseAttachments(Email $message): ?string
    {
        if (empty($message->getAttachments())) {
            return null;
        }

        return collect($message->getAttachments())
            ->map(function (DataPart $part) {
                try {
                    $body = $part->getBody();
                    $size = null;
                    if (is_resource($body)) {
                        $stat = @fstat($body);
                        $size = (\is_array($stat) && isset($stat['size'])) ? $stat['size'] : null;
                    } elseif (\is_string($body)) {
                        $size = strlen($body);
                    }
                    $contentType = $part->getContentType();
                    $contentTypeStr = null;
                    if ($contentType !== null) {
                        $contentTypeStr = method_exists($contentType, 'toString') ? $contentType->toString() : (string) $contentType;
                    }
                    return [
                        'filename' => $part->getFilename(),
                        'contentType' => $contentTypeStr,
                        'size' => $size,
                    ];
                } catch (\Throwable) {
                    return [
                        'filename' => $part->getFilename(),
                        'contentType' => null,
                        'size' => null,
                    ];
                }
            });
    }
}
