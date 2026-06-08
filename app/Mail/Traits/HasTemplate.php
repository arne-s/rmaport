<?php

namespace App\Mail\Traits;

use App\Helpers\EmailHelper;
use App\Models\EmailTemplate;
use App\Models\MailSenderProfile;
use Symfony\Component\Mime\Email as SymfonyEmail;
use Throwable;

trait HasTemplate
{
    protected EmailTemplate $template;


    /**
     * @throws Throwable
     */
    protected function initTemplate(): void
    {
        if (!isset($this->template)) {
            $this->template = EmailTemplate::query()
                ->where('class', self::class)
                ->with('senderProfile')
                ->first() ?? new EmailTemplate();

            $tokenId = $this->template->senderProfile?->microsoft_mail_token_id;
            if ($tokenId !== null) {
                $this->withSymfonyMessage(function (SymfonyEmail $message) use ($tokenId): void {
                    $message->getHeaders()->addTextHeader('X-Microsoft-Token-Id', (string) $tokenId);
                });
            }

            if ($this->template->exists) {
                $templateId = (string) $this->template->getId();
                $this->withSymfonyMessage(function (SymfonyEmail $message) use ($templateId): void {
                    $message->getHeaders()->addTextHeader(\App\Models\MailLog::EMAIL_HEADER_TEMPLATE_ID, $templateId);
                });
            }
        }
    }

    public function getTemplateVars(): array
    {
        return [];
    }

    /**
     * @throws Throwable
     */
    public function getTemplateContent(): string
    {
        $this->initTemplate();
        $content = $this->template->getContent();
        return $this->parseContent($content);
    }

    /**
     * @throws Throwable
     */
    public function getTemplateSubject(): string
    {
        $this->initTemplate();
        $subject = $this->template->getSubject();
        return $this->parseContent($subject);
    }

    private function parseContent($str): string
    {
        return $this->parseTemplateString((string) $str);
    }

    /**
     * Replace [placeholder] keys in a string (same rules as template body parsing).
     * Used e.g. for QuoteMail::messageOverride so [quote_download_button] and [quote_direct_download_button] are filled only when sending.
     *
     * @throws Throwable
     */
    protected function parseTemplateString(string $str): string
    {
        $this->initTemplate();
        $vars = array_merge($this->getTemplateRecipientVars(), $this->getTemplateVars());
        foreach ($vars as $key => $value) {
            $str = str_replace('['.$key.']', (string) $value, $str);
        }

        return $str;
    }

    /**
     * Template variables for the selected To recipient (when present).
     * Available: [user_name], [user_first_name], [user_last_name], [user_email]
     *
     * @return array<string, string>
     */
    public function getTemplateRecipientVars(): array
    {
        $this->initTemplate();
        $user = $this->template->getUsersTo()->first();

        if ($user === null) {
            return [];
        }

        return [
            'user_name' => $user->getName(),
            'user_first_name' => $user->first_name ?? '',
            'user_last_name' => $user->last_name ?? '',
            'user_email' => $user->getEmail(),
        ];
    }

    public function allowOverrideTo(): bool
    {
        return true;
    }

    /**
     * Apply template recipients (To when allowOverrideTo, CC and BCC always) to this mailable.
     * Call from build() after setting view, subject, from and optionally dynamic to().
     */
    protected function applyTemplateRecipients(): void
    {
        $this->initTemplate();
        $template = $this->template;

        if ($this->allowOverrideTo()) {
            foreach ($template->getUsersTo() as $user) {
                $email = $user->getEmail();
                if ($this->isValidTemplateRecipientEmail($email)) {
                    $this->to($email, $user->getName());
                }
            }
        }

        foreach ($template->getUsersCc() as $user) {
            $email = $user->getEmail();
            if ($this->isValidTemplateRecipientEmail($email)) {
                $this->cc($email, $user->getName());
            }
        }

        if (filled($template->cc_sender_profile_uid)) {
            $profileEmail = MailSenderProfile::where('uid', $template->cc_sender_profile_uid)
                ->with('microsoftMailToken')
                ->first()
                ?->microsoftMailToken
                ?->microsoft_email;

            if ($this->isValidTemplateRecipientEmail($profileEmail)) {
                $this->cc($profileEmail);
            }
        }

        foreach ($template->getUsersBcc() as $user) {
            $email = $user->getEmail();
            if ($this->isValidTemplateRecipientEmail($email)) {
                $this->bcc($email, $user->getName());
            }
        }
    }

    protected function isValidTemplateRecipientEmail(?string $email): bool
    {
        return EmailHelper::isValid($email);
    }
}
