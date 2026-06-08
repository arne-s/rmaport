<?php

namespace App\Mail\Unit;

use App\Helpers\EmailHelper;
use App\Mail\Traits\HasTemplate;
use App\Models\EmailTemplate;
use App\Models\MailSenderProfile;
use App\Models\Order\Main;
use App\Enums\AppointmentType;
use App\Enums\OrderSubtype;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class VerifyCustomerPaymentMail extends Mailable
{
    use HasTemplate, Queueable, SerializesModels;

    public function __construct(public Main $main) {}

    /**
     * Whether the DB template has at least one deliverable To/Cc/Bcc recipient (mirrors {@see HasTemplate::applyTemplateRecipients}).
     */
    public static function hasConfiguredRecipients(): bool
    {
        $template = EmailTemplate::query()
            ->where('class', self::class)
            ->first();

        if ($template === null) {
            return false;
        }

        foreach ($template->getUsersTo() as $user) {
            if (EmailHelper::isValid($user->getEmail())) {
                return true;
            }
        }

        foreach ($template->getUsersCc() as $user) {
            if (EmailHelper::isValid($user->getEmail())) {
                return true;
            }
        }

        foreach ($template->getUsersBcc() as $user) {
            if (EmailHelper::isValid($user->getEmail())) {
                return true;
            }
        }

        if (filled($template->cc_sender_profile_uid)) {
            $email = MailSenderProfile::query()
                ->where('uid', $template->cc_sender_profile_uid)
                ->with('microsoftMailToken')
                ->first()
                ?->microsoftMailToken
                ?->microsoft_email;

            if (EmailHelper::isValid($email)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public function getTemplateVars(): array
    {
        $fullName = $this->main->customer?->getName()
            ?? $this->main->billingCustomer?->getName()
            ?? '';

        $mainViewUrl = route('filament.app.resources.mains.view', [
            'record' => $this->main->getId(),
        ], true);

        return [
            'customer_name' => $fullName,
            'order_link' => $mainViewUrl,
            'main_number' => $this->main->getUid() ?? '',
        ];
    }

    /**
     * @throws Throwable
     */
    public function build(): self
    {
        $this
            ->view('emails.template-content', [
                'content' => $this->getTemplateContent(),
            ])
            ->subject($this->getTemplateSubject());

        $this->applyTemplateRecipients();

        return $this;
    }

    public static function preview(): self
    {
        $main = Main::query()
            ->where('subtype', OrderSubtype::Unit)
            ->whereHas('appointments', function ($q): void {
                $q->where('type', AppointmentType::Delivery)
                    ->where('is_active', true);
            })
            ->latest()
            ->first();

        if ($main === null) {
            $main = Main::query()->where('subtype', OrderSubtype::Unit)->latest()->first();
        }

        if ($main === null) {
            $main = Main::query()->latest()->firstOrFail();
        }

        return new self($main);
    }
}
