<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Order\BaseOrder;
use App\Services\Exact\Documents\ExactOrderDocumentUploader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncOrderDocumentToExactJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $documentId,
    ) {}

    public function handle(ExactOrderDocumentUploader $uploader): void
    {
        if (! config('exact.enabled')) {
            return;
        }

        $document = Document::query()->find($this->documentId);
        if ($document === null) {
            return;
        }

        $document->loadMissing('documentable');
        if (! $document->documentable instanceof BaseOrder) {
            return;
        }

        if ($document->exact_id !== null && $document->exact_id !== '') {
            return;
        }

        $guid = $uploader->uploadToCustomer($document);
        if ($guid === null) {
            Log::driver('exact-online')->warning(
                "SyncOrderDocumentToExactJob: upload failed or skipped for document {$this->documentId}"
            );
        }
    }
}
