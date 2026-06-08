<?php

namespace App\Actions;

use App\Mail\Unit\ServiceChangedAdvisorMail;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendServiceChangedAdvisorMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order, User $advisor, ?string $reason = null): void
    {
        $template = EmailTemplate::query()->where('class', ServiceChangedAdvisorMail::class)->first();
        if ($template === null) {
            Log::warning('ServiceChangedAdvisorMail: e-mailtemplate niet gevonden (class ' . ServiceChangedAdvisorMail::class . ').');

            return;
        }

        $mailable = new ServiceChangedAdvisorMail($order, $advisor, $reason);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('ServiceChangedAdvisorMail: geen e-mailadres voor adviseur, mail niet verzonden.', [
                'order_id' => $order->getId(),
                'user_id'  => $advisor->getKey(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                ServiceChangedAdvisorMail::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('ServiceChangedAdvisorMail: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
                'user_id'  => $advisor->getKey(),
            ]);
        }
    }
}
