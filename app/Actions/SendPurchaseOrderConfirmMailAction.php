<?php

namespace App\Actions;

use App\Mail\PurchaseOrderConfirmMail;
use App\Models\MailSenderProfile;
use App\Models\PurchaseOrder;
use App\Services\MicrosoftMailDispatcher;

class SendPurchaseOrderConfirmMailAction
{
    public const SENDER_PROFILE_UID = 'order';

    public function __construct(
        protected OrderMailEventLogger $logger,
        protected MicrosoftMailDispatcher $dispatcher,
    ) {}

    public function execute(
        PurchaseOrder $purchaseOrder,
        array $to,
        array $cc = [],
        array $bcc = [],
        ?string $subject = null,
        ?string $message = null,
        array $attachments = []
    ): void {
        $mailable = new PurchaseOrderConfirmMail(
            purchaseOrder: $purchaseOrder,
            subjectOverride: $subject,
            messageOverride: $message,
            attachments: $attachments
        );

        $this->dispatcher->dispatch(
            mailable: $mailable,
            to: $to,
            cc: $cc,
            bcc: $bcc,
            attachments: $attachments,
            microsoftMailTokenId: MailSenderProfile::tokenIdByUid(self::SENDER_PROFILE_UID),
        );

        $this->logger->logSent(
            context: $purchaseOrder,
            mailableClass: PurchaseOrderConfirmMail::class,
            to: $to,
            cc: $cc,
            bcc: $bcc,
            subject: $subject
        );
    }
}
