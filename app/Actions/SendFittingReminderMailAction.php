<?php

namespace App\Actions;

use App\Mail\Unit\FittingReminderMailCustomer;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendFittingReminderMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order): void
    {
        $template = EmailTemplate::query()->where('class', FittingReminderMailCustomer::class)->first();
        if ($template === null) {
            Log::warning('FittingReminderMailCustomer: e-mailtemplate niet gevonden (class ' . FittingReminderMailCustomer::class . ').');

            return;
        }

        $mailable = new FittingReminderMailCustomer($order);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('FittingReminderMailCustomer: geen e-mailadres, mail niet verzonden.', [
                'order_id' => $order->getId(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                FittingReminderMailCustomer::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('FittingReminderMailCustomer: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
            ]);
        }
    }
}
