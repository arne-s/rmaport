<?php

namespace App\Actions;

use App\Enums\OrderSubtype;
use App\Helpers\EmailHelper;
use App\Models\Order\BaseOrder;
use App\Models\Order\Order;
use App\Services\MicrosoftMailDispatcher;

class SendOrderConfirmMailAction
{
    public function __construct(
        protected OrderMailEventLogger $logger,
        protected MicrosoftMailDispatcher $dispatcher,
    ) {}

    public function execute(
        BaseOrder $order,
        ?array $to = null,
        array $cc = [],
        array $bcc = [],
        ?string $subject = null,
        ?string $message = null,
        array $attachments = []
    ): void {
        if ($order instanceof Order) {
            $order->getOrCreatePublicDownloadUuid();
        }

        $mailClass = $this->resolveMailClass($order);

        $mailable = new $mailClass(
            order: $order,
            subjectOverride: $subject,
            messageOverride: $message,
            attachments: $attachments
        );

        $toRecipients = array_values(array_filter(
            $to ?? [],
            fn (mixed $email): bool => is_string($email) && EmailHelper::isValid($email),
        ));
        if ($toRecipients === []) {
            $customerEmail = $order->customer?->getEmail();
            if (EmailHelper::isValid($customerEmail)) {
                $toRecipients[] = $customerEmail;
            }
        }

        $ccRecipients = array_values(array_filter(
            $cc,
            fn (mixed $email): bool => is_string($email) && EmailHelper::isValid($email),
        ));

        $bccRecipients = array_values(array_filter(
            $bcc,
            fn (mixed $email): bool => is_string($email) && EmailHelper::isValid($email),
        ));

        $this->dispatcher->dispatch($mailable, $toRecipients, $ccRecipients, $bccRecipients, $attachments);

        $this->logger->logSent(
            $order,
            $mailClass,
            $this->logger->normalizeRecipients($toRecipients),
            $this->logger->normalizeRecipients($ccRecipients),
            $this->logger->normalizeRecipients($bccRecipients),
            $subject
        );
    }

    private function resolveMailClass(BaseOrder $order): string
    {
        $subtype = $order->main?->getSubtype() ?? $order->getSubtype() ?? OrderSubtype::Unit;

        return match ($subtype) {
            OrderSubtype::Service => \App\Mail\Service\OrderConfirmMail::class,
            OrderSubtype::Part    => \App\Mail\Part\OrderConfirmMail::class,
            default               => \App\Mail\Unit\OrderConfirmMail::class,
        };
    }
}
