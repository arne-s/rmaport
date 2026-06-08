<?php

namespace App\Services;

use App\Models\EmailTemplate;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

class MicrosoftMailDispatcher
{
    /**
     * Dispatch a mailable. If the email template's sender profile has an Outlook token,
     * the token ID is embedded as a header so MicrosoftGraphTransport picks the right account.
     * Without a specific token, the transport falls back to the default profile's token (or logs only).
     *
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     * @param  array<int, array{path: string, name: string, mime: string}>  $attachments
     */
    public function dispatch(
        Mailable $mailable,
        array $to,
        array $cc = [],
        array $bcc = [],
        array $attachments = [],
        ?int $microsoftMailTokenId = null,
    ): void {
        $tokenId = $microsoftMailTokenId ?? $this->resolveTokenId($mailable);

        if ($tokenId !== null) {
            $mailable->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message) use ($tokenId): void {
                $message->getHeaders()->addTextHeader('X-Microsoft-Token-Id', (string) $tokenId);
            });
        }

        $mailer = Mail::to($to);
        if ($cc !== []) {
            $mailer->cc($cc);
        }
        if ($bcc !== []) {
            $mailer->bcc($bcc);
        }
        $mailer->send($mailable);
    }

    private function resolveTokenId(Mailable $mailable): ?int
    {
        $template = EmailTemplate::where('class', get_class($mailable))
            ->whereNotNull('mail_sender_profile_id')
            ->with('senderProfile.microsoftMailToken')
            ->first();

        return $template?->senderProfile?->microsoft_mail_token_id;
    }
}
