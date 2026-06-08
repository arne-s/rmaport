<?php

namespace App\Actions;

use App\Mail\Unit\ServiceConfirmationMailCustomer;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendServiceConfirmationCustomerMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order): void
    {
        $template = EmailTemplate::query()->where('class', ServiceConfirmationMailCustomer::class)->first();
        if ($template === null) {
            Log::warning('ServiceConfirmationMailCustomer: e-mailtemplate niet gevonden (class ' . ServiceConfirmationMailCustomer::class . ').');

            return;
        }

        $mailable = new ServiceConfirmationMailCustomer($order);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('ServiceConfirmationMailCustomer: geen e-mailadres, mail niet verzonden.', [
                'order_id' => $order->getId(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                ServiceConfirmationMailCustomer::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('ServiceConfirmationMailCustomer: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
            ]);
        }
    }
}
