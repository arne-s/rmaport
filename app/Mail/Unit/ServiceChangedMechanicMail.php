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

class ServiceChangedMechanicMail extends Mailable
{
    use HasAppointmentTemplateVars, HasTemplate, Queueable, SerializesModels;

    public function allowOverrideTo(): bool
    {
        return false;
    }

    public function __construct(
        public readonly Main $order,
        public readonly User $mechanic,
        public readonly ?string $reason = null,
    ) {
    }

    public function getTemplateRecipientVars(): array
    {
        return $this->resolveMechanicRecipientTemplateVars($this->mechanic);
    }

    public function getTemplateVars(): array
    {
        $dbReason = $this->order->getAppointments(AppointmentType::Service)
            ->sortByDesc('datetime')
            ->first()
            ?->comment;

        return array_merge(
            $this->buildAppointmentVars(
                $this->order->getServiceAt(),
                $this->resolveServiceLocationAddress(),
                'service',
            ),
            $this->resolveMechanicRecipientTemplateVars($this->mechanic),
            [
                'appointment_changed_reason' => $this->reason ?? $dbReason ?? '',
            ],
        );
    }

    /**
     * @return array{string|null, string}
     */
    public function resolveRecipient(): array
    {
        $email = $this->mechanic->getEmail();

        return [$email !== '' ? $email : null, $this->mechanic->getName() ?? ''];
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
