<?php

namespace App\Actions;

use App\Models\Rma;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Auth;

class RmaMailEventLogger
{
    public function logSent(Rma $rma, string $mailableClass, array $to, array $cc = [], array $bcc = [], ?string $subject = null): void
    {
        $toRecipients = $this->normalizeRecipients($to);
        $recipientText = $this->formatRecipientsForText($toRecipients);
        $eventText = 'E-mail verstuurd naar '.($recipientText !== '' ? $recipientText : '-');

        $rma->rmaEvents()->create([
            'type' => $eventText,
            'data' => [
                'mailable_class' => $mailableClass,
                'to' => $toRecipients,
                'cc' => $this->normalizeRecipients($cc),
                'bcc' => $this->normalizeRecipients($bcc),
                'subject' => $subject,
            ],
            'user_id' => Auth::id(),
        ]);
    }

    /**
     * @param  array<int, array{name?: string|null, email?: string|null}|Address|string>  $recipients
     * @return array<int, array{name: ?string, email: string}>
     */
    private function normalizeRecipients(array $recipients): array
    {
        $normalized = [];

        foreach ($recipients as $recipient) {
            if ($recipient instanceof Address) {
                $normalized[] = ['name' => $recipient->name, 'email' => $recipient->address];

                continue;
            }

            if (is_string($recipient) && $recipient !== '') {
                $normalized[] = ['name' => null, 'email' => $recipient];

                continue;
            }

            if (is_array($recipient) && filled($recipient['email'] ?? null)) {
                $normalized[] = [
                    'name' => $recipient['name'] ?? null,
                    'email' => (string) $recipient['email'],
                ];
            }
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{name: ?string, email: string}>  $recipients
     */
    private function formatRecipientsForText(array $recipients): string
    {
        return collect($recipients)
            ->map(fn (array $recipient): string => $recipient['email'])
            ->implode(', ');
    }
}
