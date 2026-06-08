<?php

namespace App\Mail\Unit;

use App\Mail\Traits\HasTemplate;
use App\Models\Order\Main;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AssemblyCompletedMail extends Mailable
{
    use HasTemplate, Queueable, SerializesModels;

    public Main $order;

    /**
     * @throws Throwable
     */
    public function __construct(Main $order)
    {
        $this->order = $order;
    }

    /**
     * @return array<string, string>
     */
    public function getTemplateVars(): array
    {
        $main = $this->order->isMain() ? $this->order : $this->order->getMain();
        $mainForLink = $main ?? $this->order;

        $orderLink = route('filament.app.resources.mains.view', [
            'record' => $mainForLink->getId(),
        ], true);

        $mainNumber = $main?->getUidFormatted() ?? $this->order->getUidFormatted();

        return [
            'main_number' => $mainNumber,
            'order_link' => $orderLink,
        ];
    }

    public static function preview(): static
    {
        $main = Main::query()->latest()->first();

        return new static($main ?? new Main);
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
