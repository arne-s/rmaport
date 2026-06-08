<?php

namespace App\Actions;

use App\Mail\OrderCustomerMessageMail;
use App\Models\Order\BaseOrder;
use Illuminate\Support\Facades\Mail;

class SendOrderCustomerMessageMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(
        BaseOrder $order,
        string|array $toAddress,
        string $subject,
        string $body,
        array $attachmentData = [],
        array $attachmentMediaIds = [],
        array $cc = [],
        array $bcc = [],
        ?string $logMailableClass = null,
        array $attachmentDeliveryDocumentMediaIds = [],
        array $attachmentFinancialMediaIds = [],
        array $attachmentFinancialOrderIds = [],
        ?int $microsoftMailTokenId = null,
    ): void {
        Mail::sendNow(new OrderCustomerMessageMail(
            toAddress: $toAddress,
            subject: $subject,
            body: $body,
            attachmentData: $attachmentData,
            orderId: $order->getMain()?->getKey() ?? $order->getKey(),
            attachmentMediaIds: $attachmentMediaIds,
            ccAddresses: array_values(array_filter(array_map(fn (array $r): ?string => $r['email'] ?? null, $cc))),
            bccAddresses: array_values(array_filter(array_map(fn (array $r): ?string => $r['email'] ?? null, $bcc))),
            attachmentDeliveryDocumentMediaIds: $attachmentDeliveryDocumentMediaIds,
            attachmentFinancialMediaIds: $attachmentFinancialMediaIds,
            attachmentFinancialOrderIds: $attachmentFinancialOrderIds,
            microsoftMailTokenId: $microsoftMailTokenId,
        ));

        $toList = is_array($toAddress) ? $toAddress : [$toAddress];
        $to = array_values(array_filter(array_map(
            fn (string $email): array => ['name' => null, 'email' => $email],
            array_filter($toList, fn ($v): bool => is_string($v) && $v !== ''),
        )));
        $this->logger->logSent(
            $order,
            $logMailableClass ?? OrderCustomerMessageMail::class,
            $to,
            $cc,
            $bcc,
            $subject,
        );
    }
}

