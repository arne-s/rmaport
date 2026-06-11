<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RmaCustomerMessageMail extends Mailable implements ShouldQueue
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
        array $ccAddresses = [],
        array $bccAddresses = [],
        public ?int $microsoftMailTokenId = null,
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

        $mail = $this
            ->to($this->toAddress)
            ->cc($this->ccAddresses)
            ->bcc($this->bccAddresses)
            ->subject($this->subject)
            ->view('emails.order-customer-message', ['body' => $this->body]);

        return $mail;
    }
}
