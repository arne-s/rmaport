<?php

namespace App\Actions;

use App\Mail\Unit\ServiceChangedMechanicMail;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendServiceChangedMechanicMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order, User $mechanic, ?string $reason = null): void
    {
        $template = EmailTemplate::query()->where('class', ServiceChangedMechanicMail::class)->first();
        if ($template === null) {
            Log::warning('ServiceChangedMechanicMail: e-mailtemplate niet gevonden (class ' . ServiceChangedMechanicMail::class . ').');

            return;
        }

        $mailable = new ServiceChangedMechanicMail($order, $mechanic, $reason);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('ServiceChangedMechanicMail: geen e-mailadres voor monteur, mail niet verzonden.', [
                'order_id' => $order->getId(),
                'user_id'  => $mechanic->getKey(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                ServiceChangedMechanicMail::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('ServiceChangedMechanicMail: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
                'user_id'  => $mechanic->getKey(),
            ]);
        }
    }
}
