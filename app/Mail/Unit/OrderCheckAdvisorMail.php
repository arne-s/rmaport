<?php

namespace App\Mail\Unit;

use App\Mail\Concerns\ResolvesOrderAdvisorAsTemplateRecipient;
use App\Mail\Traits\HasTemplate;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\Order\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class OrderCheckAdvisorMail extends Mailable
{
    use HasTemplate, Queueable, ResolvesOrderAdvisorAsTemplateRecipient, SerializesModels {
        ResolvesOrderAdvisorAsTemplateRecipient::getTemplateRecipientVars insteadof HasTemplate;
    }

    public BaseOrder $order;

    /**
     * @throws Throwable
     */
    public function __construct(BaseOrder $order)
    {
        $this->order = $order;
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
        $advisor = $this->resolveOrderAdvisor();

        $main = $this->order instanceof Main
            ? $this->order
            : $this->order->getMain();

        $order = $this->order instanceof Order
            ? $this->order
            : Order::query()->where('main_id', $main?->getId())->latest()->first();

        $orderLink = route('filament.app.resources.mains.view', [
            'record' => $main?->getId() ?? $this->order->getId(),
        ], true);

        return [
            'advisor_name' => $advisor?->getName() ?? '',
            'order_number' => (string) ($order?->getUid() ?? ''),
            'order_link' => $orderLink,
            'main_number' => (string) ($main?->getUid() ?? ''),
        ];
    }

    public static function preview(): static
    {
        return new static(Main::resolveForAdvisorOrderEmailPreview());
    }

    public function build(): self
    {
        $advisor = $this->resolveOrderAdvisor();

        $mail = $this
            ->view('emails.template-content', [
                'content' => $this->getTemplateContent(),
            ])
            ->subject($this->getTemplateSubject());

        if ($advisor?->getEmail()) {
            $mail->to($advisor->getEmail(), $advisor->getName() ?? '');
        }

        $this->applyTemplateRecipients();

        return $mail;
    }
}
