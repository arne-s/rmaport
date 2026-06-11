<?php

namespace App\Actions;

use App\Mail\RmaCustomerMessageMail;
use App\Models\Rma;
use Illuminate\Support\Facades\Mail;

class SendRmaCustomerMessageMailAction
{
    public function __construct(protected RmaMailEventLogger $logger) {}

    public function execute(
        Rma $rma,
        string|array $toAddress,
        string $subject,
        string $body,
        array $cc = [],
        array $bcc = [],
        ?int $microsoftMailTokenId = null,
    ): void {
        Mail::sendNow(new RmaCustomerMessageMail(
            toAddress: $toAddress,
            subject: $subject,
            body: $body,
            ccAddresses: array_values(array_filter(array_map(
                fn (array $recipient): ?string => $recipient['email'] ?? null,
                $cc,
            ))),
            bccAddresses: array_values(array_filter(array_map(
                fn (array $recipient): ?string => $recipient['email'] ?? null,
                $bcc,
            ))),
            microsoftMailTokenId: $microsoftMailTokenId,
        ));

        $toList = is_array($toAddress) ? $toAddress : [$toAddress];
        $to = array_values(array_filter(array_map(
            fn (string $email): array => ['name' => null, 'email' => $email],
            array_filter($toList, fn ($value): bool => is_string($value) && $value !== ''),
        )));

        $this->logger->logSent(
            $rma,
            RmaCustomerMessageMail::class,
            $to,
            $cc,
            $bcc,
            $subject,
        );
    }
}
