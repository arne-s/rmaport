<?php

namespace App\Mail\Unit;

use App\Enums\AppointmentType;
use App\Mail\Traits\HasAppointmentTemplateVars;
use App\Mail\Traits\HasTemplate;
use App\Models\Order\Main;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ServiceConfirmationMailCustomer extends Mailable
{
    use HasAppointmentTemplateVars, HasTemplate, Queueable, SerializesModels;

    public function allowOverrideTo(): bool
    {
        return false;
    }

    public function __construct(public readonly Main $order)
    {
    }

    public function getTemplateVars(): array
    {
        return $this->buildCustomerAppointmentVars(
            $this->resolveServiceLocationAddress(),
            'service-customer',
            AppointmentType::Service,
        );
    }

    /**
     * @return array{string|null, string}  [email, name]
     */
    public function resolveRecipient(): array
    {
        return [
            $this->order->customer?->getEmail() ?? $this->order->billingCustomer?->getEmail(),
            $this->order->customer?->getName() ?? $this->order->billingCustomer?->getName() ?? '',
        ];
    }

    public static function preview(): static
    {
        return new static(
            Main::query()->latest()->whereHas('appointments', function ($q) {
                $q->where('type', 'service');
            })->first(),
        );
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

        $this->applyAdvisorDealerContactCcToMailable($mail);

        $this->applyTemplateRecipients();

        return $mail;
    }
}
