<?php

namespace App\Actions;

use App\Mail\Unit\DeliveryReminderMail;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendDeliveryReminderAdvisorMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order, User $advisor): void
    {
        $template = EmailTemplate::query()->where('class', DeliveryReminderMail::class)->first();
        if ($template === null) {
            Log::warning('DeliveryReminderMail: e-mailtemplate niet gevonden (class ' . DeliveryReminderMail::class . ').');

            return;
        }

        $mailable = new DeliveryReminderMail($order, $advisor);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('DeliveryReminderMail: geen e-mailadres voor adviseur, mail niet verzonden.', [
                'order_id' => $order->getId(),
                'user_id' => $advisor->getKey(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                DeliveryReminderMail::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('DeliveryReminderMail: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
                'user_id' => $advisor->getKey(),
            ]);
        }
    }
}
