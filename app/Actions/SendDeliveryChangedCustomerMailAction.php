<?php

namespace App\Actions;

use App\Mail\Unit\DeliveryChangedMailCustomer;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendDeliveryChangedCustomerMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order, ?string $reason = null): void
    {
        $template = EmailTemplate::query()->where('class', DeliveryChangedMailCustomer::class)->first();
        if ($template === null) {
            Log::warning('DeliveryChangedMailCustomer: e-mailtemplate niet gevonden (class ' . DeliveryChangedMailCustomer::class . ').');

            return;
        }

        $mailable = new DeliveryChangedMailCustomer($order, $reason);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('DeliveryChangedMailCustomer: geen e-mailadres, mail niet verzonden.', [
                'order_id' => $order->getId(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                DeliveryChangedMailCustomer::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('DeliveryChangedMailCustomer: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
            ]);
        }
    }
}
