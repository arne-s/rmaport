<?php

namespace App\Actions;

use App\Mail\Unit\ServiceChangedMailCustomer;
use App\Models\EmailTemplate;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendServiceChangedCustomerMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(Main $order, ?string $reason = null): void
    {
        $template = EmailTemplate::query()->where('class', ServiceChangedMailCustomer::class)->first();
        if ($template === null) {
            Log::warning('ServiceChangedMailCustomer: e-mailtemplate niet gevonden (class ' . ServiceChangedMailCustomer::class . ').');

            return;
        }

        $mailable = new ServiceChangedMailCustomer($order, $reason);
        [$toEmail, $toName] = $mailable->resolveRecipient();

        if (! $toEmail) {
            Log::info('ServiceChangedMailCustomer: geen e-mailadres, mail niet verzonden.', [
                'order_id' => $order->getId(),
            ]);

            return;
        }

        try {
            Mail::send($mailable);
            $this->logger->logSent(
                $order,
                ServiceChangedMailCustomer::class,
                [['email' => $toEmail, 'name' => $toName]],
                [],
            );
        } catch (\Throwable $e) {
            Log::error('ServiceChangedMailCustomer: verzenden mislukt: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
            ]);
        }
    }
}
