<?php

namespace App\Actions;

use App\Mail\Unit\ServiceReminderAdvisorMail;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendServiceReminderAdvisorMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order, User $advisor): void
    {
        $template = EmailTemplate::query()->where('class', ServiceReminderAdvisorMail::class)->first();
        if ($template === null) {
            Log::warning('ServiceReminderAdvisorMail: e-mailtemplate niet gevonden (class ' . ServiceReminderAdvisorMail::class . ').');

            return;
        }

        $mailable = new ServiceReminderAdvisorMail($order, $advisor);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('ServiceReminderAdvisorMail: geen e-mailadres voor adviseur, mail niet verzonden.', [
                'order_id' => $order->getId(),
                'user_id' => $advisor->getKey(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                ServiceReminderAdvisorMail::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('ServiceReminderAdvisorMail: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
                'user_id' => $advisor->getKey(),
            ]);
        }
    }
}
