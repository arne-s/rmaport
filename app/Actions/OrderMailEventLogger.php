<?php

namespace App\Actions;

use App\Models\EmailTemplate;
use App\Enums\OrderType;
use App\Models\Order\BaseOrder;
use App\Models\Order\Invoice;
use App\Models\Order\Main;
use App\Models\Order\Quote;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class OrderMailEventLogger
{
    public function logSent(BaseOrder|Quote|Main $context, string $mailableClass, array $to, array $cc = [], array $bcc = [], ?string $subject = null): void
    {
        $templateName = $this->resolveTemplateName($mailableClass, $subject);
        $toRecipients = $this->normalizeRecipients($to);
        $ccRecipients = $this->normalizeRecipients($cc);
        $bccRecipients = $this->normalizeRecipients($bcc);

        if ($toRecipients === []) {
            [$templateTo, $templateCc, $templateBcc] = $this->resolveRecipientsFromTemplate($mailableClass);
            $toRecipients = $templateTo;
            if ($ccRecipients === []) {
                $ccRecipients = $templateCc;
            }
            if ($bccRecipients === []) {
                $bccRecipients = $templateBcc;
            }
        }

        $recipientText = $this->formatRecipientsForText($toRecipients);
        $eventText = 'E-mail verstuurd "' . $templateName . '" naar ' . ($recipientText !== '' ? $recipientText : '-');

        $payload = [
            'type' => $eventText,
            'data' => [
                'mailable_class' => $mailableClass,
                'template_name' => $templateName,
                'to' => $toRecipients,
                'cc' => $ccRecipients,
                'bcc' => $bccRecipients,
                'subject' => $subject,
            ],
            'user_id' => Auth::id(),
        ];

        if ($context instanceof Invoice && $this->isStandaloneInvoice($context)) {
            $context->orderEvents()->create($payload);

            return;
        }

        $main = $this->resolveMain($context);
        if ($main === null) {
            return;
        }

        $main->orderEvents()->create($payload);
    }

    public function logScheduled(
        BaseOrder|Quote|Main|Invoice $context,
        string $mailableClass,
        int $delaySeconds,
        ?Carbon $scheduledAt = null,
    ): void {
        if ($delaySeconds < 1) {
            return;
        }

        $scheduledAt ??= now()->addSeconds($delaySeconds);
        $templateName = $this->resolveTemplateName($mailableClass);
        $delayLabel = $this->formatDelayLabel($delaySeconds);
        $scheduledLabel = $scheduledAt->timezone(config('app.timezone'))->format('d-m-Y H:i');

        $eventText = sprintf(
            'E-mail gepland: "%s" — verzending over %s (%s)',
            $templateName,
            $delayLabel,
            $scheduledLabel,
        );

        $payload = [
            'type' => $eventText,
            'data' => [
                'mailable_class' => $mailableClass,
                'template_name' => $templateName,
                'delay_seconds' => $delaySeconds,
                'scheduled_at' => $scheduledAt->toIso8601String(),
            ],
            'user_id' => Auth::id(),
        ];

        if ($context instanceof Invoice && $this->isStandaloneInvoice($context)) {
            $context->orderEvents()->create($payload);

            return;
        }

        $main = $this->resolveMain($context);
        if ($main === null) {
            return;
        }

        $main->orderEvents()->create($payload);
    }

    private function formatDelayLabel(int $delaySeconds): string
    {
        if ($delaySeconds >= 3600) {
            $hours = intdiv($delaySeconds, 3600);
            $minutes = intdiv($delaySeconds % 3600, 60);
            $parts = [$hours === 1 ? '1 uur' : "{$hours} uur"];

            if ($minutes > 0) {
                $parts[] = $minutes === 1 ? '1 minuut' : "{$minutes} minuten";
            }

            return implode(' en ', $parts);
        }

        if ($delaySeconds >= 60) {
            $minutes = intdiv($delaySeconds, 60);

            return $minutes === 1 ? '1 minuut' : "{$minutes} minuten";
        }

        return $delaySeconds === 1 ? '1 seconde' : "{$delaySeconds} seconden";
    }

    private function isStandaloneInvoice(Invoice $invoice): bool
    {
        return $invoice->getType() === OrderType::Invoice
            && $invoice->main_id === null
            && $invoice->order_id === null;
    }

    public function resolveTemplateName(string $mailableClass, ?string $subject = null): string
    {
        $template = EmailTemplate::query()->where('class', $mailableClass)->first();
        if ($template !== null && filled($template->name)) {
            return (string) $template->name;
        }

        if (is_string($subject) && trim($subject) !== '') {
            return trim($subject);
        }

        return class_basename($mailableClass);
    }

    public function normalizeRecipients(array $recipients): array
    {
        $mapped = array_map(function ($recipient): ?array {
            if ($recipient instanceof Address) {
                return [
                    'name' => $recipient->name,
                    'email' => $recipient->address,
                ];
            }

            if (is_string($recipient)) {
                return ['name' => null, 'email' => $recipient];
            }

            if (is_array($recipient)) {
                $email = $recipient['email'] ?? $recipient['address'] ?? null;
                if (!is_string($email) || $email === '') {
                    return null;
                }

                $name = $recipient['name'] ?? null;
                return ['name' => is_string($name) ? $name : null, 'email' => $email];
            }

            return null;
        }, $recipients);

        return array_values(array_filter($mapped));
    }

    public function formatRecipientsForText(array $recipients): string
    {
        $normalized = $this->normalizeRecipients($recipients);

        return implode(', ', array_map(function (array $recipient): string {
            $name = $recipient['name'] ?? null;
            $email = (string) ($recipient['email'] ?? '');
            if ($email === '') {
                return '';
            }

            return filled($name) ? "{$name} <{$email}>" : "<{$email}>";
        }, $normalized));
    }

    protected function resolveMain(BaseOrder|Quote|Main $context): ?Main
    {
        if ($context instanceof Main) {
            return $context;
        }

        if ($context instanceof Quote) {
            return $context->main;
        }

        return $context->getMain();
    }

    protected function resolveRecipientsFromTemplate(string $mailableClass): array
    {
        $template = EmailTemplate::query()->where('class', $mailableClass)->first();
        if ($template === null) {
            return [[], [], []];
        }

        $to = $template->getUsersTo()->map(fn ($user): array => [
            'name' => method_exists($user, 'getName') ? $user->getName() : null,
            'email' => method_exists($user, 'getEmail') ? $user->getEmail() : null,
        ])->all();

        $cc = $template->getUsersCc()->map(fn ($user): array => [
            'name' => method_exists($user, 'getName') ? $user->getName() : null,
            'email' => method_exists($user, 'getEmail') ? $user->getEmail() : null,
        ])->all();

        $bcc = $template->getUsersBcc()->map(fn ($user): array => [
            'name' => method_exists($user, 'getName') ? $user->getName() : null,
            'email' => method_exists($user, 'getEmail') ? $user->getEmail() : null,
        ])->all();

        return [$this->normalizeRecipients($to), $this->normalizeRecipients($cc), $this->normalizeRecipients($bcc)];
    }
}

