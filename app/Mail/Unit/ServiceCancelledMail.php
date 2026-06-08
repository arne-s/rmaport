<?php

namespace App\Mail\Unit;

use App\Enums\AppointmentType;
use App\Mail\Traits\HasAppointmentTemplateVars;
use App\Mail\Traits\HasTemplate;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Service cancellation for assigned advisors and mechanics. Template placeholders include [user_first_name],
 * [customer_first_name], [appointment_date], [appointment_time], [appointment_street],
 * [appointment_city], [appointment_description], [fitting_type], [order_number], [main_number], [order_link],
 * [calendar_link], [cancellation_reason], [appointment_changed_reason].
 */
class ServiceCancelledMail extends Mailable
{
    use HasAppointmentTemplateVars, HasTemplate, Queueable, SerializesModels;

    /**
     * @throws Throwable
     */
    public function __construct(
        public readonly Main $order,
        public readonly User $recipient,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getTemplateRecipientVars(): array
    {
        return $this->resolveMechanicRecipientTemplateVars($this->recipient);
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

        $appointmentComment = $this->resolveLatestServiceAppointment()?->comment;

        $resolvedReason = $this->reason ?? $appointmentComment ?? '';

        return array_merge(
            $this->buildServiceAppointmentTemplateVars([
                'cancellation_reason' => $resolvedReason !== '' ? $resolvedReason : '(geen reden opgegeven)',
                'appointment_changed_reason' => $resolvedReason,
            ]),
            $this->resolveMechanicRecipientTemplateVars($this->recipient),
        );
    }

    /**
     * @return array{string|null, string}
     */
    public function resolveRecipient(): array
    {
        $email = $this->recipient->getEmail();

        return [$email !== null && $email !== '' ? $email : null, $this->recipient->getName() ?? ''];
    }

    public static function preview(): static
    {
        $order = self::resolveMainForServiceMechanicEmailPreview();
        $mechanic = self::resolveMechanicUserForEmailPreview($order);

        return new static($order, $mechanic);
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
