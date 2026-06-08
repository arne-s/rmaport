<?php

namespace App\Mail\Transport;

use App\Enums\MailLogStatus;
use App\Models\MailLog;
use App\Models\MailSenderProfile;
use App\Models\MicrosoftMailToken;
use App\Services\MicrosoftMailService;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

class MicrosoftGraphTransport extends AbstractTransport
{
    public function __construct(private MicrosoftMailService $mailService)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $tokenId = $this->extractTokenId($email);
        $token = $tokenId !== null
            ? MicrosoftMailToken::find($tokenId)
            : MailSenderProfile::where('is_default', true)->first()?->microsoftMailToken;

        $subject = $email->getSubject() ?? '(geen onderwerp)';

        $messageId = $email->getHeaders()->getHeaderBody(MailLog::EMAIL_HEADER_MESSAGE_ID);

        if (! $token) {
            if ($this->shouldFallbackToSmtp()) {
                Log::info('MicrosoftGraphTransport: no token found, using SMTP.', [
                    'subject' => $subject,
                    'to'      => $this->extractAddresses($email->getTo()),
                ]);

                app(MailManager::class)->mailer('smtp')->getSymfonyTransport()->send(
                    $message->getOriginalMessage(),
                    $message->getEnvelope(),
                );

                return;
            }

            Log::info('MicrosoftGraphTransport: no token found and no SMTP configured — mail not sent.', [
                'subject' => $subject,
                'to'      => $this->extractAddresses($email->getTo()),
            ]);

            if ($messageId) {
                MailLog::whereMessageId($messageId)->update(['status' => MailLogStatus::Failed]);
            }

            return;
        }

        if ($messageId) {
            MailLog::whereMessageId($messageId)->update(['from' => $token->microsoft_email]);
        }

        $to          = $this->extractAddresses($email->getTo());
        $cc          = $this->extractAddresses($email->getCc());
        $bcc         = $this->extractAddresses($email->getBcc());
        $htmlBody    = (string) ($email->getHtmlBody() ?? $email->getTextBody() ?? '');
        $attachments = $this->extractAttachments($email);

        $success = $this->mailService->sendMail(
            tokenId:     $token->id,
            to:          $to,
            cc:          $cc,
            bcc:         $bcc,
            subject:     $subject,
            htmlBody:    $htmlBody,
            attachments: $attachments,
        );

        Log::info('MicrosoftGraphTransport: mail ' . ($success ? 'verzonden' : 'mislukt') . '.', [
            'subject' => $subject,
            'to'      => $to,
            'from'    => $token->microsoft_email,
        ]);

        if (! $success && $messageId) {
            MailLog::whereMessageId($messageId)->update(['status' => MailLogStatus::Failed]);
        }
    }

    private function shouldFallbackToSmtp(): bool
    {
        return filled((string) config('mail.mailers.smtp.host', ''));
    }

    public function __toString(): string
    {
        return 'microsoft-graph://graph.microsoft.com';
    }

    private function extractTokenId(Email $email): ?int
    {
        $header = $email->getHeaders()->get('X-Microsoft-Token-Id');
        if ($header === null) {
            return null;
        }

        $value = $header->getBodyAsString();
        $email->getHeaders()->remove('X-Microsoft-Token-Id');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  \Symfony\Component\Mime\Address[]  $addresses
     * @return array<int, string>
     */
    private function extractAddresses(array $addresses): array
    {
        return array_values(array_map(fn ($a) => $a->getAddress(), $addresses));
    }

    /**
     * @return array<int, array{path: string, name: string, mime: string}>
     */
    private function extractAttachments(Email $email): array
    {
        $result = [];

        foreach ($email->getAttachments() as $part) {
            if (! $part instanceof DataPart) {
                continue;
            }

            $body = $part->getBody();
            if (is_resource($body)) {
                $body = stream_get_contents($body) ?: '';
            }
            if (! is_string($body) || $body === '') {
                continue;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'mail_attach_');
            if ($tmp === false) {
                continue;
            }

            file_put_contents($tmp, $body);

            $result[] = [
                'path' => $tmp,
                'name' => $part->getFilename() ?? basename($tmp),
                'mime' => $part->getMediaType().'/'.$part->getMediaSubtype(),
            ];
        }

        return $result;
    }
}
