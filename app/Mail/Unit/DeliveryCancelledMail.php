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
use Throwable;

/**
 * Delivery cancellation for assigned advisors and mechanics. Template placeholders include [user_first_name],
 * [customer_first_name], [appointment_date], [appointment_time], [appointment_street],
 * [appointment_city], [appointment_description], [fitting_type], [order_number], [main_number], [order_link],
 * [calendar_link], [cancellation_reason], [appointment_changed_reason].
 */
class DeliveryCancelledMail extends Mailable
{
    use HasAppointmentTemplateVars, HasTemplate, Queueable, SerializesModels;

    /**
     * @throws Throwable
     */
    public function __construct(
        public readonly Main $order,
        public readonly ?string $reason = null,
        public readonly ?User $recipient = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getTemplateRecipientVars(): array
    {
        $recipient = $this->resolveRecipientUser();

        if ($recipient === null) {
            return [
                'user_name' => '',
                'user_first_name' => '',
                'user_last_name' => '',
                'user_email' => '',
            ];
        }

        return $this->resolveMechanicRecipientTemplateVars($recipient);
    }

    public function allowOverrideTo(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public function getTemplateVars(): array
    {
        $this->order->loadMissing(['customer', 'billingCustomer', 'advisor']);

        $appointmentComment = $this->resolveLatestDeliveryAppointment()?->comment;

        $resolvedReason = $this->reason ?? $appointmentComment ?? '';

        $recipient = $this->resolveRecipientUser();

        return array_merge(
            $this->buildDeliveryAppointmentTemplateVars([
                'cancellation_reason' => $resolvedReason !== '' ? $resolvedReason : '(geen reden opgegeven)',
                'appointment_changed_reason' => $resolvedReason,
            ]),
            $recipient !== null ? $this->resolveMechanicRecipientTemplateVars($recipient) : [],
        );
    }

    /**
     * @return array{string|null, string}
     */
    public function resolveRecipient(): array
    {
        $recipient = $this->resolveRecipientUser();
        $email = $recipient?->getEmail();

        return [$email !== null && $email !== '' ? $email : null, $recipient?->getName() ?? ''];
    }

    private function resolveRecipientUser(): ?User
    {
        return $this->recipient ?? $this->order->advisor;
    }

    public static function preview(): static
    {
        $order = Main::resolveForDeliveryEmailPreview(forCustomerMail: false);
        $order->loadMissing(['advisor']);

        $appointment = Appointment::query()
            ->where('order_id', $order->getId())
            ->where('type', AppointmentType::Delivery)
            ->where(function ($query): void {
                $query->whereNull('segment')->orWhere('segment', 'appointment');
            })
            ->with('advisors')
            ->latest('datetime')
            ->first();

        $advisor = $appointment?->advisors->first() ?? $order->advisor;

        return new static($order, recipient: $advisor);
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

        $this->applyTemplateRecipients();

        return $mail;
    }
}
