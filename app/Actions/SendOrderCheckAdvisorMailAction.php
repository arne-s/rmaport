<?php

namespace App\Actions;

use App\Enums\OrderSubtype;
use App\Models\Order\BaseOrder;
use App\Services\MicrosoftMailDispatcher;

class SendOrderCheckAdvisorMailAction
{
    public function __construct(
        protected OrderMailEventLogger $logger,
        protected MicrosoftMailDispatcher $dispatcher,
    ) {}

    public function execute(BaseOrder $order): void
    {
        $subtype = $order->main?->getSubtype() ?? $order->getSubtype() ?? OrderSubtype::Unit;

        if ($subtype !== OrderSubtype::Unit) {
            return;
        }

        $advisorEmail = $order->advisor?->getEmail();
        if (! is_string($advisorEmail) || $advisorEmail === '') {
            return;
        }

        $to = [[
            'name' => $order->advisor?->getName(),
            'email' => $advisorEmail,
        ]];

        $mailable = new \App\Mail\Unit\OrderCheckAdvisorMail($order);
        $this->dispatcher->dispatch($mailable, [$advisorEmail]);
        $this->logger->logSent($order, \App\Mail\Unit\OrderCheckAdvisorMail::class, $to);
    }
}
