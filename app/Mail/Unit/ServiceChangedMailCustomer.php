<?php

namespace App\Mail\Unit;

use App\Enums\AppointmentType;
use App\Mail\Traits\HasAppointmentTemplateVars;
use App\Mail\Traits\HasTemplate;
use App\Models\Order\Main;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ServiceChangedMailCustomer extends Mailable
{
    use HasAppointmentTemplateVars, HasTemplate, Queueable, SerializesModels;

    public function allowOverrideTo(): bool
    {
        return false;
    }

    public function __construct(
        public readonly Main $order,
        public readonly ?string $reason = null,
    ) {
    }

    public function getTemplateVars(): array
    {
        $dbReason = $this->order->getAppointments(AppointmentType::Service)
            ->sortByDesc('datetime')
            ->first()
            ?->comment;

        return array_merge(
            $this->buildCustomerAppointmentVars(
                $this->resolveServiceLocationAddress(),
                'service-customer',
                AppointmentType::Service,
            ),
            ['appointment_changed_reason' => $this->reason ?? $dbReason ?? ''],
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
            Main::query()->latest()->whereHas('appointments', fn ($q) => $q
                ->where('type', AppointmentType::Service->value)
                ->whereNotNull('comment')
            )->first() ?? Main::query()->latest()->first(),
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
