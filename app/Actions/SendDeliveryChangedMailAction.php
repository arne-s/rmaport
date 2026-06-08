<?php

namespace App\Actions;

use App\Mail\Unit\DeliveryChangedMail;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendDeliveryChangedMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order, User $advisor, ?string $reason = null): void
    {
        $template = EmailTemplate::query()->where('class', DeliveryChangedMail::class)->first();
        if ($template === null) {
            Log::warning('DeliveryChangedMail: e-mailtemplate niet gevonden (class ' . DeliveryChangedMail::class . ').');

            return;
        }

        $mailable = new DeliveryChangedMail($order, $advisor, $reason);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('DeliveryChangedMail: geen e-mailadres voor adviseur, mail niet verzonden.', [
                'order_id' => $order->getId(),
                'user_id' => $advisor->getKey(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                DeliveryChangedMail::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('DeliveryChangedMail: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
                'user_id' => $advisor->getKey(),
            ]);
        }
    }
}
