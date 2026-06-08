<?php

namespace App\Mail;

use App\Helpers\EmailHelper;
use App\Mail\Traits\HasTemplate;
use App\Models\EmailTemplate;
use App\Models\MailSenderProfile;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Models\PackingSlip;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PackingSlipMail extends Mailable
{
    use HasTemplate;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Main $main,
        public Order $order,
        public PackingSlip $packingSlip,
        public string $toAddress,
        public string $toName = '',
        /** Shipping recipient first name (customer or dealer); used for [first_name] in the template. */
        public string $recipientFirstName = '',
        public ?string $attachmentPath = null,
        public ?string $attachmentContent = null,
        public ?string $attachmentName = null,
        public ?string $attachmentMime = null,
    ) {
    }

    public function allowOverrideTo(): bool
    {
        return false;
    }

    public function getTemplateVars(): array
    {
        $order = $this->order;
        $order->loadMissing(['customer', 'billingCustomer', 'shippingCustomer']);

        $customerName = $order->customer?->getName()
            ?? $order->billingCustomer?->getName()
            ?? $order->shippingCustomer?->getName()
            ?? '';
        $firstName = $order->customer?->getFirstName()
            ?? $order->billingCustomer?->getFirstName()
            ?? $order->shippingCustomer?->getFirstName()
            ?? $this->recipientFirstName
            ?? '';

        return [
            'customer_first_name' => $customerName,
            'first_name' => $firstName,
            'main_number' => $this->main->getUidFormatted() ?? $this->main->getUid() ?? '',
            'order_number' => (string) ($this->order->getUid() ?? ''),
            'packing_slip_number' => $this->packingSlip->uid ?? '',
            'order_link' => route('filament.app.resources.mains.view', [
                'record' => $this->order->getId(),
            ], true),
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
        $uid = self::emailTemplate()?->senderProfile?->uid;

        return MailSenderProfile::modalFromDisplayLabel($uid);
    }

    public static function microsoftMailTokenId(): ?int
    {
        $tokenId = self::emailTemplate()?->senderProfile?->microsoft_mail_token_id;
        if ($tokenId !== null) {
            return $tokenId;
        }

        return MailSenderProfile::query()->where('is_default', true)->value('microsoft_mail_token_id');
    }

    /**
     * Recipient keys for the mail modal CC field from the e-mail template (users + CC sender profile).
     *
     * @return array<int, string>
     */
    public static function defaultCcRecipientKeysFromEmailTemplate(): array
    {
        $template = self::emailTemplate();
        if ($template === null) {
            return [];
        }

        $keys = [];

        foreach ($template->getUsersCc() as $user) {
            $email = $user->getEmail();
            if ($email !== '') {
                $keys[] = 'user_'.$user->getKey();
            }
        }

        if (filled($template->cc_sender_profile_uid)) {
            $profileEmail = MailSenderProfile::query()
                ->where('uid', $template->cc_sender_profile_uid)
                ->with('microsoftMailToken')
                ->first()
                ?->microsoftMailToken
                ?->microsoft_email;

            if (EmailHelper::isValid($profileEmail)) {
                $keys[] = $profileEmail;
            }
        }

        return array_values(array_unique($keys));
    }

    public function interpolatePlaceholders(string $str): string
    {
        return $this->parseContent($str);
    }

    public static function preview(): static
    {
        $order = Order::query()
            ->whereHas('main')
            ->latest()
            ->first();

        if (! $order instanceof Order) {
            $baseOrder = BaseOrder::query()->latest()->whereHas('main')->first();
            $order = $baseOrder instanceof Order ? $baseOrder : new Order();
        }

        $main = $order->main instanceof Main ? $order->main : new Main();
        $packingSlip = new PackingSlip([
            'uid' => '0000',
        ]);

        $order->loadMissing(['customer', 'billingCustomer', 'shippingCustomer']);

        $previewFirst = $order->customer?->getFirstName()
            ?? $order->billingCustomer?->getFirstName()
            ?? $order->shippingCustomer?->getFirstName()
            ?? '';

        return new static(
            main: $main,
            order: $order,
            packingSlip: $packingSlip,
            toAddress: 'preview@example.com',
            toName: 'Preview',
            recipientFirstName: (string) $previewFirst,
        );
    }

    public function build(): self
    {
        $mail = $this
            ->view('emails.template-content', [
                'content' => $this->getTemplateContent(),
            ])
            ->subject($this->getTemplateSubject())
            ->to($this->toAddress, $this->toName);

        $filename = $this->attachmentName ?: ('afleverbon-' . ($this->packingSlip->uid ?? 'document') . '.pdf');
        $mime = $this->attachmentMime ?: 'application/pdf';

        if (is_string($this->attachmentContent) && $this->attachmentContent !== '') {
            $mail->attachData($this->attachmentContent, $filename, ['mime' => $mime]);
        } elseif (
            is_string($this->attachmentPath)
            && $this->attachmentPath !== ''
            && is_file($this->attachmentPath)
        ) {
            $mail->attach($this->attachmentPath, [
                'as' => $filename,
                'mime' => $mime,
            ]);
        }

        $this->applyTemplateRecipients();

        return $mail;
    }
}
