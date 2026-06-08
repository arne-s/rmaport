<?php

namespace App\Mail\Unit;

use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Mail\Traits\HasTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class OrderReadyForQuoteMail extends Mailable
{
    use HasTemplate, Queueable, SerializesModels;

    public BaseOrder $order;

    /**
     * @throws Throwable
     */
    public function __construct(BaseOrder $order)
    {
        $this->order = $order;
    }

    public function getTemplateVars(): array
    {
        $main = $this->order instanceof Main
            ? $this->order
            : $this->order->getMain();

        if ($main !== null) {
            $main->loadMissing('customer');
        }

        $customer = $main?->customer;

        $orderLink = route('filament.app.resources.mains.view', [
            'record' => $main?->getId() ?? $this->order->getId(),
        ], true);

        return [
            'customer_first_name' => $customer?->getFirstName() ?? $customer?->getName() ?? '',
            'order_number' => (string) ($this->order->getUid() ?? ''),
            'order_link' => $orderLink,
            'main_number' => $main?->getUid() ?? '',
        ];
    }

    public static function preview(): static
    {
        return new static(
            BaseOrder::query()->whereHas('quote')->latest()->first(),
        );
    }

    public function build(): self
    {
        $mail = $this
            ->view('emails.template-content', [
                'content' => $this->getTemplateContent(),
            ])
            ->subject($this->getTemplateSubject());

        $this->applyTemplateRecipients();

        return $mail;
    }
}
