<?php

namespace App\Actions;

use App\Mail\Unit\ServiceConfirmationMechanicMail;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendServiceConfirmationMechanicMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order, User $mechanic): void
    {
        $template = EmailTemplate::query()->where('class', ServiceConfirmationMechanicMail::class)->first();
        if ($template === null) {
            Log::warning('ServiceConfirmationMechanicMail: e-mailtemplate niet gevonden (class ' . ServiceConfirmationMechanicMail::class . ').');

            return;
        }

        $mailable = new ServiceConfirmationMechanicMail($order, $mechanic);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('ServiceConfirmationMechanicMail: geen e-mailadres voor monteur, mail niet verzonden.', [
                'order_id'  => $order->getId(),
                'user_id'   => $mechanic->getKey(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                ServiceConfirmationMechanicMail::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('ServiceConfirmationMechanicMail: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
                'user_id'  => $mechanic->getKey(),
            ]);
        }
    }
}
