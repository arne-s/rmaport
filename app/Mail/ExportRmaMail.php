<?php

namespace App\Mail;

use App\Filament\Resources\ImportTasks\Support\ImportBatchMailRecipients;
use App\Filament\Resources\ImportTasks\Support\ImportBatchUploadedDocumentMailAttachments;
use App\Mail\Traits\HasTemplate;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\ImportBatch;
use App\Models\ImportExport;
use App\Models\MailSenderProfile;
use App\Support\FormatDisplayDate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class ExportRmaMail extends Mailable
{
    use HasTemplate;
    use Queueable;
    use SerializesModels;

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

    public function __construct(
        public ImportBatch $batch,
        public ImportExport $export,
        string|array $toAddress,
        public ?string $subjectOverride = null,
        public ?string $messageOverride = null,
        array $ccAddresses = [],
        array $bccAddresses = [],
        public ?int $microsoftMailTokenId = null,
        public array $attachmentMediaIds = [],
    ) {
        $this->toAddress = $toAddress;
        $this->ccAddresses = $ccAddresses;
        $this->bccAddresses = $bccAddresses;
    }

    public function allowOverrideTo(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function getTemplateVars(): array
    {
        $this->batch->loadMissing(['importRows.customer', 'importRows.source.customer']);

        $customer = ImportBatchMailRecipients::resolveCustomer($this->batch);

        return [
            'customer.name' => $customer instanceof Customer ? (string) $customer->getName() : '',
            'customer.email' => $customer instanceof Customer ? (string) ($customer->getEmail() ?? '') : '',
            'import.uid' => (string) ($this->batch->uid ?? ''),
            'import.reference' => (string) ($this->batch->reference ?? ''),
            'import.shipment_reference' => (string) ($this->batch->shipment_reference ?? ''),
            'import.file_name' => (string) ($this->batch->file_name ?? ''),
            'import.import_date' => self::formatBatchDate($this->batch->import_date),
            'import.shipment_date' => self::formatBatchDate($this->batch->shipment_date),
            'import.track_trace_nr' => (string) ($this->batch->track_trace_nr ?? ''),
        ];
    }

    public static function getRawTemplateContentFromDatabase(): string
    {
        $template = EmailTemplate::query()->where('class', self::class)->first();
        $content = $template?->getContent();

        if (is_string($content) && trim($content) !== '') {
            return $content;
        }

        return '';
    }

    public static function getRawTemplateSubjectFromDatabase(): string
    {
        $template = EmailTemplate::query()->where('class', self::class)->first();
        $subject = $template?->getSubject();

        return is_string($subject) ? $subject : '';
    }

    public static function emailTemplate(): ?EmailTemplate
    {
        return EmailTemplate::query()
            ->where('class', self::class)
            ->with('senderProfile')
            ->first();
    }

    public static function modalFromDisplayLabel(): string
    {
        $uid = self::emailTemplate()?->senderProfile?->uid ?? 'orders';

        return MailSenderProfile::modalFromDisplayLabel($uid);
    }

    public static function microsoftMailTokenId(): ?int
    {
        $tokenId = self::emailTemplate()?->senderProfile?->microsoft_mail_token_id;
        if ($tokenId !== null) {
            return $tokenId;
        }

        return MailSenderProfile::query()->where('uid', 'orders')->value('microsoft_mail_token_id');
    }

    public function interpolatePlaceholders(string $str): string
    {
        foreach ($this->getTemplateVars() as $key => $value) {
            $str = str_replace('['.$key.']', (string) $value, $str);
        }

        return $str;
    }

    public static function preview(): static
    {
        $batch = ImportBatch::query()->latest()->first() ?? new ImportBatch([
            'uid' => '0000',
            'reference' => 'REF-0000',
            'file_name' => 'import.xlsx',
        ]);

        $export = ImportExport::query()->latest()->first() ?? new ImportExport([
            'uid' => '0000',
            'file_name' => 'export.xlsx',
            'file_disk' => 'local',
        ]);

        return new static(
            batch: $batch,
            export: $export,
            toAddress: 'preview@example.com',
        );
    }

    public function build(): self
    {
        $content = $this->messageOverride !== null && $this->messageOverride !== ''
            ? $this->interpolatePlaceholders($this->messageOverride)
            : $this->getTemplateContent();

        $subject = $this->subjectOverride !== null && $this->subjectOverride !== ''
            ? $this->interpolatePlaceholders($this->subjectOverride)
            : $this->getTemplateSubject();

        $mail = $this
            ->to($this->toAddress)
            ->cc($this->ccAddresses)
            ->bcc($this->bccAddresses)
            ->subject($subject)
            ->view('emails.template-content', [
                'content' => $content,
            ]);

        $path = "exports/{$this->export->import_id}/{$this->export->uid}.xlsx";

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

    private static function formatBatchDate(mixed $date): string
    {
        if ($date === null) {
            return '';
        }

        if ($date instanceof Carbon) {
            return FormatDisplayDate::longDate($date);
        }

        try {
            return FormatDisplayDate::longDate(Carbon::parse($date));
        } catch (\Throwable) {
            return '';
        }
    }
}
