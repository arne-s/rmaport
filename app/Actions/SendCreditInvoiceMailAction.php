<?php

namespace App\Actions;

use App\Helpers\EmailHelper;
use App\Mail\CreditInvoiceMail;
use App\Models\Order\CreditInvoice;
use App\Services\MicrosoftMailDispatcher;

class SendCreditInvoiceMailAction
{
    public function __construct(
        protected OrderMailEventLogger $logger,
        protected MicrosoftMailDispatcher $dispatcher,
    ) {}

    public function execute(
        CreditInvoice $invoice,
        ?array $to = null,
        array $cc = [],
        array $bcc = [],
        ?string $subject = null,
        ?string $message = null,
    ): void {
        $invoice->getOrCreatePublicDownloadUuid();

        $mailable = new CreditInvoiceMail(
            invoice: $invoice,
            subjectOverride: $subject,
            messageOverride: $message,
        );

        $toRecipients = array_values(array_filter(
            $to ?? [],
            fn (mixed $email): bool => is_string($email) && EmailHelper::isValid($email),
        ));
        if ($toRecipients === []) {
            $customerEmail = $invoice->customer?->getEmail();
            if (EmailHelper::isValid($customerEmail)) {
                $toRecipients[] = $customerEmail;
            }
        }

        $ccRecipients = array_values(array_filter(
            $cc,
            fn (mixed $email): bool => is_string($email) && EmailHelper::isValid($email),
        ));
        if ($ccRecipients === [] && $to === null) {
            $companyEmail = $invoice->billingCustomer?->getEmail();
            if (EmailHelper::isValid($companyEmail)) {
                $ccRecipients[] = $companyEmail;
            }
        }

        $bccRecipients = array_values(array_filter(
            $bcc,
            fn (mixed $email): bool => is_string($email) && EmailHelper::isValid($email),
        ));

        $this->dispatcher->dispatch($mailable, $toRecipients, $ccRecipients, $bccRecipients);

        $this->logger->logSent(
            $invoice,
            CreditInvoiceMail::class,
            $this->logger->normalizeRecipients($toRecipients),
            $this->logger->normalizeRecipients($ccRecipients),
            $this->logger->normalizeRecipients($bccRecipients),
            $subject,
        );
    }
}
