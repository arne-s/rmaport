<?php

namespace App\Mail\Unit;

use App\Enums\AppointmentType;
use App\Mail\Traits\HasAppointmentTemplateVars;
use App\Mail\Traits\HasTemplate;
use App\Models\Appointment;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Advisor passing changed notification. Template placeholders include [user_first_name] (advisor),
 * [customer_first_name], [appointment_date], [appointment_time], [appointment_street],
 * [appointment_city], [appointment_description], [fitting_type], [order_number], [calendar_link],
 * [appointment_changed_reason].
 */
class FittingChangedMail extends Mailable
{
    use HasAppointmentTemplateVars, HasTemplate, Queueable, SerializesModels;

    public function allowOverrideTo(): bool
    {
        return false;
    }

    public function __construct(
        public readonly Main $order,
        public readonly User $advisor,
        public readonly ?string $reason = null,
    ) {
    }

    public function getTemplateRecipientVars(): array
    {
        return $this->resolveMechanicRecipientTemplateVars($this->advisor);
    }

    public function getTemplateVars(): array
    {
        $this->order->loadMissing(['customer', 'billingCustomer', 'activeFittingAppointment']);

        $dbReason = $this->order->getAppointments(AppointmentType::Fitting)
            ->sortByDesc('datetime')
            ->first()
            ?->comment;

        return array_merge(
            $this->buildAppointmentVars(
                $this->order->getFittingAt(),
                $this->resolveFittingLocationAddress(),
                'fitting',
            ),
            $this->resolveMechanicRecipientTemplateVars($this->advisor),
            ['appointment_changed_reason' => $this->reason ?? $dbReason ?? ''],
        );
    }

    /**
     * @return array{string|null, string}  [email, name]
     */
    public function resolveRecipient(): array
    {
        $email = $this->advisor->getEmail();

        return [$email !== null && $email !== '' ? $email : null, $this->advisor->getName() ?? ''];
    }

    public static function preview(): static
    {
        $order = Main::resolveForFittingEmailPreview(forCustomerMail: false);
        $advisor = Appointment::query()
            ->where('order_id', $order->getId())
            ->where('type', AppointmentType::Fitting)
            ->where('is_active', true)
            ->with('advisors')
            ->latest('datetime')
            ->first()
            ?->advisors
            ->first();

        return new static($order, $advisor ?? $order->advisor ?? new User);
    }

    public function build(): self
    {
        $mail = $this
            ->view('emails.template-content', [
                'content' => $this->getTemplateContent(),
            ])
            ->subject($this->getTemplateSubject());

        [$toEmail, $toName] = $this->resolveRecipient();
        if ($toEmail) {
            $mail->to($toEmail, $toName);
        }

        $fittingNote = $this->order->getFittingNote() ?? [];
        $dealerContactEmail = trim((string) ($fittingNote['advisor_dealer_email'] ?? ''));
        $dealerContactName  = trim((string) ($fittingNote['advisor_dealer_name']  ?? ''));
        if ($dealerContactEmail !== '' && filter_var($dealerContactEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->cc($dealerContactEmail, $dealerContactName);
        }

        foreach ((array) ($fittingNote['extra_cc'] ?? []) as $ccEmail) {
            if (is_string($ccEmail) && filter_var($ccEmail, FILTER_VALIDATE_EMAIL)) {
                $mail->cc($ccEmail);
            }
        }

        foreach ((array) ($fittingNote['extra_bcc'] ?? []) as $bccEmail) {
            if (is_string($bccEmail) && filter_var($bccEmail, FILTER_VALIDATE_EMAIL)) {
                $mail->bcc($bccEmail);
            }
        }

        $this->applyTemplateRecipients();

        return $mail;
    }
}
