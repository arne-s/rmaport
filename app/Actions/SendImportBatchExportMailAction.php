<?php

namespace App\Actions;

use App\Mail\ExportRmaMail;
use App\Models\ImportBatch;
use App\Models\ImportExport;
use Illuminate\Support\Facades\Mail;

class SendImportBatchExportMailAction
{
    public function execute(
        ImportBatch $batch,
        ImportExport $export,
        string|array $toAddress,
        string $subject,
        string $body,
        array $ccEmails = [],
        array $bccEmails = [],
        ?int $microsoftMailTokenId = null,
        array $attachmentMediaIds = [],
    ): void {
        Mail::sendNow(new ExportRmaMail(
            batch: $batch,
            export: $export,
            toAddress: $toAddress,
            subjectOverride: $subject,
            messageOverride: $body,
            ccAddresses: $ccEmails,
            bccAddresses: $bccEmails,
            microsoftMailTokenId: $microsoftMailTokenId,
            attachmentMediaIds: $attachmentMediaIds,
        ));
    }
}
