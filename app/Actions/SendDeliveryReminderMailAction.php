<?php

namespace App\Actions;

use App\Mail\Unit\DeliveryReminderMailCustomer;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendDeliveryReminderMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order): void
    {
        $template = EmailTemplate::query()->where('class', DeliveryReminderMailCustomer::class)->first();
        if ($template === null) {
            Log::warning('DeliveryReminderMailCustomer: e-mailtemplate niet gevonden (class ' . DeliveryReminderMailCustomer::class . ').');

            return;
        }

        $mailable = new DeliveryReminderMailCustomer($order);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('DeliveryReminderMailCustomer: geen e-mailadres, mail niet verzonden.', [
                'order_id' => $order->getId(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                DeliveryReminderMailCustomer::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('DeliveryReminderMailCustomer: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
            ]);
        }
    }
}
