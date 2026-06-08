<?php

namespace App\Actions;

use App\Mail\CustomerMessageMail;
use App\Models\Customer;
use App\Models\Order\BaseOrder;
use Illuminate\Support\Facades\Mail;

class SendCustomerMessageMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    public function execute(
        Customer $customer,
        string|array $toAddress,
        string $subject,
        string $body,
        array $attachmentMediaIds = [],
        array $cc = [],
        array $bcc = [],
        ?BaseOrder $orderContext = null,
        ?int $microsoftMailTokenId = null,
    ): void {
        Mail::send(new CustomerMessageMail(
            toAddress: $toAddress,
            subject: $subject,
            body: $body,
            customerId: $customer->getKey(),
            attachmentMediaIds: $attachmentMediaIds,
            ccAddresses: array_values(array_filter(array_map(fn (array $r): ?string => $r['email'] ?? null, $cc))),
            bccAddresses: array_values(array_filter(array_map(fn (array $r): ?string => $r['email'] ?? null, $bcc))),
            microsoftMailTokenId: $microsoftMailTokenId,
        ));

        if ($orderContext !== null) {
            $toList = is_array($toAddress) ? $toAddress : [$toAddress];
            $to = array_values(array_filter(array_map(
                fn (string $email): array => ['name' => $customer->getName(), 'email' => $email],
                array_filter($toList, fn ($v): bool => is_string($v) && $v !== ''),
            )));

            $this->logger->logSent(
                $orderContext,
                CustomerMessageMail::class,
                $to,
                $cc,
                $bcc,
                $subject
            );
        }
    }
}

