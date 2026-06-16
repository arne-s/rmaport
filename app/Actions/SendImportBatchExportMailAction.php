<?php

namespace App\Actions;

use App\Mail\ImportBatchExportMail;
use App\Models\ImportExport;
use Illuminate\Support\Facades\Mail;

class SendImportBatchExportMailAction
{
    public function execute(
        ImportExport $export,
        string|array $toAddress,
        string $subject,
        string $body,
        array $ccEmails = [],
        array $bccEmails = [],
        ?int $microsoftMailTokenId = null,
        array $attachmentMediaIds = [],
    ): void {
        Mail::sendNow(new ImportBatchExportMail(
            toAddress: $toAddress,
            subject: $subject,
            body: $body,
            export: $export,
            ccAddresses: $ccEmails,
            bccAddresses: $bccEmails,
            microsoftMailTokenId: $microsoftMailTokenId,
            attachmentMediaIds: $attachmentMediaIds,
        ));
    }
}
