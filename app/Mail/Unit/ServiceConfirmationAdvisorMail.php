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

class ServiceConfirmationAdvisorMail extends Mailable
{
    use HasAppointmentTemplateVars, HasTemplate, Queueable, SerializesModels;

    public function allowOverrideTo(): bool
    {
        return false;
    }

    public function __construct(
        public readonly Main $order,
        public readonly User $advisor,
    ) {
    }

    public function getTemplateRecipientVars(): array
    {
        return $this->resolveMechanicRecipientTemplateVars($this->advisor);
    }

    public function getTemplateVars(): array
    {
        return array_merge(
            $this->buildAppointmentVars(
                $this->order->getServiceAt(),
                $this->resolveServiceLocationAddress(),
                'service',
            ),
            $this->resolveMechanicRecipientTemplateVars($this->advisor),
        );
    }

    /**
     * @return array{string|null, string}
     */
    public function resolveRecipient(): array
    {
        $email = $this->advisor->getEmail();

        return [$email !== null && $email !== '' ? $email : null, $this->advisor->getName() ?? ''];
    }

    public static function preview(): static
    {
        $order = self::resolveMainForServiceMechanicEmailPreview();
        $advisor = Appointment::query()
            ->where('order_id', $order->getId())
            ->where('type', AppointmentType::Service)
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

        $this->applyTemplateRecipients();

        return $mail;
    }
}
