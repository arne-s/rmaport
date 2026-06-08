<?php

namespace App\Actions;

use App\Mail\ReleaseOrderConfirmMail;
use App\Models\ReleaseOrder;
use App\Services\MicrosoftMailDispatcher;

class SendReleaseOrderConfirmMailAction
{
    public function __construct(
        protected OrderMailEventLogger $logger,
        protected MicrosoftMailDispatcher $dispatcher,
    ) {}

    public function execute(
        ReleaseOrder $releaseOrder,
        array $to,
        array $cc = [],
        array $bcc = [],
        ?string $subject = null,
        ?string $message = null,
        array $attachments = []
    ): void {
        $mailable = new ReleaseOrderConfirmMail(
            releaseOrder: $releaseOrder,
            subjectOverride: $subject,
            messageOverride: $message,
            attachments: $attachments
        );

        $this->dispatcher->dispatch($mailable, $to, $cc, $bcc, $attachments);

        $this->logger->logSent(
            context: $releaseOrder,
            mailableClass: ReleaseOrderConfirmMail::class,
            to: $to,
            cc: $cc,
            bcc: $bcc,
            subject: $subject
        );
    }
}
