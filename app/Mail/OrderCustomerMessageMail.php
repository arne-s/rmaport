<?php

namespace App\Mail;

use App\Filament\Resources\OrderResource\Support\FinancialDocumentMailAttachments;
use App\Filament\Resources\OrderResource\Support\OrderUploadedDocumentMailAttachments;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

class OrderCustomerMessageMail extends Mailable implements ShouldQueue
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
     * Inline attachments (e.g. order confirmation HTML). Must be UTF-8 for queued mail; PDFs use attachmentDeliveryDocumentMediaIds.
     *
     * @var array<int, array{content: string, filename: string, mime: string}>
     */
    public $attachmentData = [];

    /**
     * Order ID to load uploaded media from. Used in build() to attach files without putting binary in the queue.
     *
     * @var int|null
     */
    public $orderId = null;

    /**
     * Media IDs (uploaded document/image collections) to attach. Loaded in build().
     *
     * @var array<int, int>
     */
    public $attachmentMediaIds = [];

    /**
     * Media IDs in collection delivery_documents (e.g. delivery note PDF). Loaded from disk in build() so the queue payload stays non-binary.
     *
     * @var array<int, int>
     */
    public array $attachmentDeliveryDocumentMediaIds = [];

    /**
     * Media IDs on main collection financial_documents. Loaded in build().
     *
     * @var array<int, int>
     */
    public array $attachmentFinancialMediaIds = [];

    /**
     * Financial order document IDs (quote/order/invoice PDFs). Loaded in build().
     *
     * @var array<int, int>
     */
    public array $attachmentFinancialOrderIds = [];

    public function __construct(
        $toAddress,
        $subject,
        string $body,
        $attachmentData = [],
        $orderId = null,
        $attachmentMediaIds = [],
        $ccAddresses = [],
        $bccAddresses = [],
        array $attachmentDeliveryDocumentMediaIds = [],
        array $attachmentFinancialMediaIds = [],
        array $attachmentFinancialOrderIds = [],
        public ?int $microsoftMailTokenId = null,
    ) {
        $this->toAddress = is_array($toAddress) ? array_values(array_filter($toAddress)) : (string) $toAddress;
        $this->subject = $subject;
        $this->body = $body;
        $this->attachmentData = is_array($attachmentData) ? $attachmentData : [];
        $this->orderId = $orderId;
        $this->attachmentMediaIds = is_array($attachmentMediaIds) ? array_map('intval', $attachmentMediaIds) : [];
        $this->ccAddresses = is_array($ccAddresses) ? array_values(array_filter($ccAddresses)) : [];
        $this->bccAddresses = is_array($bccAddresses) ? array_values(array_filter($bccAddresses)) : [];
        $this->attachmentDeliveryDocumentMediaIds = array_map('intval', $attachmentDeliveryDocumentMediaIds);
        $this->attachmentFinancialMediaIds = array_map('intval', $attachmentFinancialMediaIds);
        $this->attachmentFinancialOrderIds = array_map('intval', $attachmentFinancialOrderIds);
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

        foreach ($this->attachmentData as $item) {
            if (!is_array($item) || !isset($item['content'], $item['filename'])) {
                continue;
            }
            $mail->attachData(
                $item['content'],
                $item['filename'],
                ['mime' => $item['mime'] ?? 'application/octet-stream']
            );
        }

        if ($this->orderId !== null && $this->attachmentMediaIds !== []) {
            OrderUploadedDocumentMailAttachments::attachToMailable(
                $mail,
                (int) $this->orderId,
                $this->attachmentMediaIds,
            );
        }

        if ($this->orderId !== null
            && ($this->attachmentFinancialMediaIds !== [] || $this->attachmentFinancialOrderIds !== [])) {
            $mainOrder = BaseOrder::withoutGlobalScopes()->find($this->orderId);
            if ($mainOrder !== null && $mainOrder->isMain()) {
                $main = $mainOrder instanceof Main
                    ? $mainOrder
                    : Main::withoutGlobalScopes()->find($this->orderId);

                if ($main instanceof Main) {
                    FinancialDocumentMailAttachments::attachToMailable(
                        $mail,
                        $main,
                        $this->attachmentFinancialMediaIds,
                        $this->attachmentFinancialOrderIds,
                    );
                }
            }
        }

        if ($this->orderId !== null && $this->attachmentDeliveryDocumentMediaIds !== []) {
            OrderUploadedDocumentMailAttachments::attachToMailable(
                $mail,
                (int) $this->orderId,
                $this->attachmentDeliveryDocumentMediaIds,
            );
        }

        return $mail;
    }
}
