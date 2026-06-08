<?php

namespace App\Actions;

use App\Mail\Unit\FittingConfirmationMailCustomer;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendFittingConfirmationCustomerMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order): void
    {
        $template = EmailTemplate::query()->where('class', FittingConfirmationMailCustomer::class)->first();
        if ($template === null) {
            Log::warning('FittingConfirmationMailCustomer: e-mailtemplate niet gevonden (class ' . FittingConfirmationMailCustomer::class . ').');

            return;
        }

        $mailable = new FittingConfirmationMailCustomer($order);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('FittingConfirmationMailCustomer: geen e-mailadres, mail niet verzonden.', [
                'order_id' => $order->getId(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                FittingConfirmationMailCustomer::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('FittingConfirmationMailCustomer: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
            ]);
        }
    }
}
