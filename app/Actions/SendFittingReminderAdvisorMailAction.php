<?php

namespace App\Actions;

use App\Mail\Unit\FittingReminderMail;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendFittingReminderAdvisorMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order, User $advisor): void
    {
        $template = EmailTemplate::query()->where('class', FittingReminderMail::class)->first();
        if ($template === null) {
            Log::warning('FittingReminderMail: e-mailtemplate niet gevonden (class ' . FittingReminderMail::class . ').');

            return;
        }

        $mailable = new FittingReminderMail($order, $advisor);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('FittingReminderMail: geen e-mailadres voor adviseur, mail niet verzonden.', [
                'order_id' => $order->getId(),
                'user_id' => $advisor->getKey(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                FittingReminderMail::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('FittingReminderMail: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
                'user_id' => $advisor->getKey(),
            ]);
        }
    }
}
