<?php

namespace App\Mail\Part;

use App\Mail\Traits\HasTemplate;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\Order\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class OrderReadyForPurchaseMail extends Mailable
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

    /**
     * @return array<string, string>
     */
    public function getTemplateVars(): array
    {
        $this->order->loadMissing(['advisor', 'main.advisor']);

        $advisor = $this->order instanceof Main
            ? $this->order->advisor
            : ($this->order->advisor ?? $this->order->getMain()?->advisor);

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
        $mail = $this
            ->view('emails.template-content', [
                'content' => $this->getTemplateContent(),
            ])
            ->subject($this->getTemplateSubject());

        $this->applyTemplateRecipients();

        return $mail;
    }
}
