<?php

namespace App\Actions;

use App\Mail\Unit\ServiceReminderMechanicMail;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendServiceReminderMechanicMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order, User $mechanic): void
    {
        $template = EmailTemplate::query()->where('class', ServiceReminderMechanicMail::class)->first();
        if ($template === null) {
            Log::warning('ServiceReminderMechanicMail: e-mailtemplate niet gevonden (class ' . ServiceReminderMechanicMail::class . ').');

            return;
        }

        $mailable = new ServiceReminderMechanicMail($order, $mechanic);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('ServiceReminderMechanicMail: geen e-mailadres voor monteur, mail niet verzonden.', [
                'order_id' => $order->getId(),
                'user_id'  => $mechanic->getKey(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                ServiceReminderMechanicMail::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('ServiceReminderMechanicMail: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
                'user_id'  => $mechanic->getKey(),
            ]);
        }
    }
}
