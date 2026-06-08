<?php

namespace App\Actions;

use App\Mail\Unit\ServiceConfirmationAdvisorMail;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendServiceConfirmationAdvisorMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order, User $advisor): void
    {
        $template = EmailTemplate::query()->where('class', ServiceConfirmationAdvisorMail::class)->first();
        if ($template === null) {
            Log::warning('ServiceConfirmationAdvisorMail: e-mailtemplate niet gevonden (class ' . ServiceConfirmationAdvisorMail::class . ').');

            return;
        }

        $mailable = new ServiceConfirmationAdvisorMail($order, $advisor);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('ServiceConfirmationAdvisorMail: geen e-mailadres voor adviseur, mail niet verzonden.', [
                'order_id' => $order->getId(),
                'user_id'  => $advisor->getKey(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                ServiceConfirmationAdvisorMail::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('ServiceConfirmationAdvisorMail: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
                'user_id'  => $advisor->getKey(),
            ]);
        }
    }
}
