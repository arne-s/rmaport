<?php

namespace App\Mail\Unit;

use App\Mail\Traits\HasAppointmentTemplateVars;
use App\Mail\Traits\HasTemplate;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ServiceReminderMechanicMail extends Mailable
{
    use HasAppointmentTemplateVars, HasTemplate, Queueable, SerializesModels;

    public function allowOverrideTo(): bool
    {
        return false;
    }

    public function __construct(
        public readonly Main $order,
        public readonly User $mechanic,
    ) {
    }

    public function getTemplateRecipientVars(): array
    {
        return $this->resolveMechanicRecipientTemplateVars($this->mechanic);
    }

    public function getTemplateVars(): array
    {
        return array_merge(
            $this->buildAppointmentVars(
                $this->order->getServiceAt(),
                $this->resolveServiceLocationAddress(),
                'service',
            ),
            $this->resolveMechanicRecipientTemplateVars($this->mechanic),
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
