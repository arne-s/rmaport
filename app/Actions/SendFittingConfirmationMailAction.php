<?php

namespace App\Actions;

use App\Mail\Unit\FittingConfirmationMail;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendFittingConfirmationMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order, User $advisor): void
    {
        $template = EmailTemplate::query()->where('class', FittingConfirmationMail::class)->first();
        if ($template === null) {
            Log::warning('FittingConfirmationMail: e-mailtemplate niet gevonden (class ' . FittingConfirmationMail::class . ').');

            return;
        }

        $mailable = new FittingConfirmationMail($order, $advisor);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('FittingConfirmationMail: geen e-mailadres voor adviseur, mail niet verzonden.', [
                'order_id' => $order->getId(),
                'user_id' => $advisor->getKey(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                FittingConfirmationMail::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('FittingConfirmationMail: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
                'user_id' => $advisor->getKey(),
            ]);
        }
    }
}
