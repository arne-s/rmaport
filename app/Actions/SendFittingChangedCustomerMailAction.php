<?php

namespace App\Actions;

use App\Mail\Unit\FittingChangedMailCustomer;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendFittingChangedCustomerMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order, ?string $reason = null): void
    {
        $template = EmailTemplate::query()->where('class', FittingChangedMailCustomer::class)->first();
        if ($template === null) {
            Log::warning('FittingChangedMailCustomer: e-mailtemplate niet gevonden (class ' . FittingChangedMailCustomer::class . ').');

            return;
        }

        $mailable = new FittingChangedMailCustomer($order, $reason);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('FittingChangedMailCustomer: geen e-mailadres, mail niet verzonden.', [
                'order_id' => $order->getId(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                FittingChangedMailCustomer::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('FittingChangedMailCustomer: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
            ]);
        }
    }
}
