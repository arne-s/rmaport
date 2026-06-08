<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;

class CustomerMessageMail extends Mailable implements ShouldQueue
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

    /**
     * Customer ID to load uploaded media from. Used in build() to attach files without putting binary in the queue.
     *
     * @var int|null
     */
    public $customerId = null;

    /**
     * Media IDs (documents collection) to attach. Loaded in build().
     *
     * @var array<int, int>
     */
    public $attachmentMediaIds = [];

    public function __construct(
        $toAddress,
        $subject,
        string $body,
        $customerId = null,
        $attachmentMediaIds = [],
        $ccAddresses = [],
        $bccAddresses = [],
        public ?int $microsoftMailTokenId = null,
    )
    {
        $this->toAddress = is_array($toAddress) ? array_values(array_filter($toAddress)) : (string) $toAddress;
        $this->subject = $subject;
        $this->body = $body;
        $this->customerId = $customerId;
        $this->attachmentMediaIds = is_array($attachmentMediaIds) ? array_map('intval', $attachmentMediaIds) : [];
        $this->ccAddresses = is_array($ccAddresses) ? array_values(array_filter($ccAddresses)) : [];
        $this->bccAddresses = is_array($bccAddresses) ? array_values(array_filter($bccAddresses)) : [];
    }

    public function build(): self
    {
        if ($this->microsoftMailTokenId !== null) {
            $tokenId = $this->microsoftMailTokenId;
            $this->withSymfonyMessage(static function (Email $message) use ($tokenId): void {
                $message->getHeaders()->addTextHeader('X-Microsoft-Token-Id', (string) $tokenId);
            });
        }

        $mail = $this
            ->to($this->toAddress)
            ->cc($this->ccAddresses)
            ->bcc($this->bccAddresses)
            ->subject($this->subject)
            ->view('emails.order-customer-message', ['body' => $this->body]);

        if ($this->customerId !== null && $this->attachmentMediaIds !== []) {
            $customer = Customer::query()->find($this->customerId);
            if ($customer !== null) {
                foreach ($this->attachmentMediaIds as $mediaId) {
                    $media = $customer->getMedia('documents')->firstWhere('id', $mediaId);
                    if ($media === null) {
                        continue;
                    }
                    $path = $media->getPathRelativeToRoot();
                    if (!Storage::disk($media->disk)->exists($path)) {
                        continue;
                    }
                    $content = Storage::disk($media->disk)->get($path);
                    if ($content === null) {
                        continue;
                    }
                    $filename = $media->file_name ?: ($media->name ? $media->name . '.' . $media->extension : 'document-' . $media->id . '.' . $media->extension);
                    $mail->attachData(
                        $content,
                        $filename,
                        ['mime' => $media->mime_type ?? 'application/octet-stream']
                    );
                }
            }
        }

        return $mail;
    }
}
