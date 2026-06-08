<?php

namespace App\Mail;

use App\Mail\Traits\HasTemplate;
use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PurchaseOrderConfirmMail extends Mailable
{
    use HasTemplate, Queueable, SerializesModels;

    public PurchaseOrder $purchaseOrder;

    /** Custom subject from send modal (optional). */
    public ?string $subjectOverride = null;

    /** Custom message/body from send modal (optional). */
    public ?string $messageOverride = null;

    /**
     * @var array<int, array{path: string, name: string, mime: string}>
     */
    private array $attachmentFiles = [];

    /**
     * @param  array<int, array{path: string, name: string, mime: string}>  $attachments
     */
    public function __construct(
        PurchaseOrder $purchaseOrder,
        ?string $subjectOverride = null,
        ?string $messageOverride = null,
        array $attachments = [],
    ) {
        $this->purchaseOrder = $purchaseOrder;
        $this->subjectOverride = $subjectOverride;
        $this->messageOverride = $messageOverride;
        $this->attachmentFiles = $attachments;
    }

    public function allowOverrideTo(): bool
    {
        return false;
    }

    public function getTemplateVars(): array
    {
        return [
            'purchase_order_number' => $this->purchaseOrder->getReferenceNumber(),
        ];
    }

    public static function preview(): static
    {
        $po = PurchaseOrder::query()->latest()->first();

        return new static($po ?? new PurchaseOrder);
    }

    public function build(): self
    {
        $content = $this->messageOverride !== null && $this->messageOverride !== ''
            ? $this->messageOverride
            : $this->getTemplateContent();
        $content = $this->replaceTemplateVars($content);

        $subject = $this->subjectOverride !== null && $this->subjectOverride !== ''
            ? $this->subjectOverride
            : $this->getTemplateSubject();
        $subject = $this->replaceTemplateVars($subject);

        $mail = $this
            ->view('emails.template-content', [
                'content' => $content,
            ])
            ->subject($subject);

        foreach ($this->attachmentFiles as $attachment) {
            $mail->attach($attachment['path'], [
                'as' => $attachment['name'],
                'mime' => $attachment['mime'],
            ]);
        }

        return $mail;
    }

    /**
     * Default body when no EmailTemplate content is set.
     */
    protected function getDefaultTemplateContent(): string
    {
        return '<p>Geachte heer/mevrouw,</p>' .
            '<p style="font-size: 16px; text-align: center;">Bij deze ontvang je de inkooporder: #<strong>[purchase_order_number]</strong>.</p>' .
            '<p style="text-align: center;">Met vriendelijke groeten,<br>RD-Mobility</p>';
    }

    /**
     * Template content with placeholders (e.g. [purchase_order_number]).
     * Variables are only replaced in build() when sending the mail.
     *
     * @throws Throwable
     */
    public function getTemplateContent(): string
    {
        $this->initTemplate();
        $content = $this->template->getContent();

        if ($content === null || $content === '') {
            $content = $this->getDefaultTemplateContent();
        }

        return $content;
    }

    /**
     * Template subject with placeholders. Variables are only replaced in build() when sending.
     *
     * @throws Throwable
     */
    public function getTemplateSubject(): string
    {
        $this->initTemplate();
        $subject = $this->template->getSubject();

        if ($subject === null || $subject === '') {
            $subject = 'Inkooporder #[purchase_order_number]';
        }

        return $subject;
    }

    /**
     * Replace [variable] placeholders in a string with template vars.
     */
    protected function replaceTemplateVars(string $str): string
    {
        $vars = array_merge($this->getTemplateRecipientVars(), $this->getTemplateVars());
        foreach ($vars as $key => $value) {
            $str = str_replace('[' . $key . ']', (string) $value, $str);
        }

        return $str;
    }
}
