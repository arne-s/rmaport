<?php

namespace App\Mail;

use App\Mail\Traits\HasTemplate;
use App\Models\ReleaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ReleaseOrderConfirmMail extends Mailable
{
    use HasTemplate, Queueable, SerializesModels;

    public ReleaseOrder $releaseOrder;

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
        ReleaseOrder $releaseOrder,
        ?string $subjectOverride = null,
        ?string $messageOverride = null,
        array $attachments = [],
    ) {
        $this->releaseOrder = $releaseOrder;
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
        $this->releaseOrder->loadMissing('main');
        $main = $this->releaseOrder->main;

        return [
            'release_order_number' => $this->releaseOrder->getReferenceNumber(),
            'po_number' => $main !== null ? (string) ($main->getReference() ?? '') : '',
        ];
    }

    public static function preview(): static
    {
        $ro = ReleaseOrder::query()->latest()->first();

        return new static($ro ?? new ReleaseOrder);
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
            '<p>Bij deze ontvangt u afroepverzoek: #<strong>[release_order_number]</strong>.</p>' .
            '<p>Met vriendelijke groeten,<br>RD-Mobility</p>';
    }

    /**
     * Template content with placeholders (e.g. [release_order_number]).
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
            $subject = 'Afroepverzoek #[release_order_number]';
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
