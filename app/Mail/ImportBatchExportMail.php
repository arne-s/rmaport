<?php

namespace App\Mail;

use App\Filament\Resources\ImportTasks\Support\ImportBatchUploadedDocumentMailAttachments;
use App\Models\ImportExport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ImportBatchExportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @var string|array<int, string>
     */
    public string|array $toAddress;

    /**
     * @var array<int, string>
     */
    public array $ccAddresses = [];

    /**
     * @var array<int, string>
     */
    public array $bccAddresses = [];

    public $subject;

    public string $body;

    public function __construct(
        string|array $toAddress,
        string $subject,
        string $body,
        public ImportExport $export,
        array $ccAddresses = [],
        array $bccAddresses = [],
        public ?int $microsoftMailTokenId = null,
        public array $attachmentMediaIds = [],
    ) {
        $this->toAddress = $toAddress;
        $this->subject = $subject;
        $this->body = $body;
        $this->ccAddresses = $ccAddresses;
        $this->bccAddresses = $bccAddresses;
    }

    public function build(): self
    {
        if ($this->microsoftMailTokenId !== null) {
            $tokenId = $this->microsoftMailTokenId;
            $this->withSymfonyMessage(static function (\Symfony\Component\Mime\Email $message) use ($tokenId): void {
                $message->getHeaders()->addTextHeader('X-Microsoft-Token-Id', (string) $tokenId);
            });
        }

        $path = "exports/{$this->export->import_id}/{$this->export->uid}.xlsx";

        $mail = $this
            ->to($this->toAddress)
            ->cc($this->ccAddresses)
            ->bcc($this->bccAddresses)
            ->subject($this->subject)
            ->view('emails.order-customer-message', ['body' => $this->body]);

        if (Storage::disk($this->export->file_disk)->exists($path)) {
            $mail->attach(Storage::disk($this->export->file_disk)->path($path), [
                'as' => $this->export->file_name,
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        ImportBatchUploadedDocumentMailAttachments::attachToMailable(
            $mail,
            (int) $this->export->import_id,
            $this->attachmentMediaIds,
        );

        return $mail;
    }
}
