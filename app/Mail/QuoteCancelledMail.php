<?php

namespace App\Mail;

use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\Order\Quote;
use Throwable;
use App\Mail\Traits\HasTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class QuoteCancelledMail extends Mailable
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

    public function allowOverrideTo(): bool
    {
        return false;
    }

    public function getTemplateVars(): array
    {
        $order = $this->order;
        $order->loadMissing(['customer', 'billingCustomer', 'main']);

        $orderLink = route('filament.app.resources.mains.view', [
            'record' => $order instanceof Main
                ? $order->getId()
                : ($order->getMain()?->getId() ?? $order->getId()),
        ], true);

        [, $billingRecipientName] = $this->order->getBillingRecipient();
        $customerName = $order->customer?->getName()
            ?? $order->billingCustomer?->getName()
            ?? $billingRecipientName
            ?? '';
        $firstName = $order->customer?->getFirstName()
            ?? $order->billingCustomer?->getFirstName()
            ?? '';

        $main = $order instanceof Quote ? $order->main : null;
        $mainNumber = $main !== null ? ($main->getUidFormatted() ?? $main->getUid() ?? '') : '';
        $quoteNumber = $order instanceof Quote ? ($order->getUidFormatted() ?? $order->getUid() ?? '') : '';

        return [
            'order_number' => (string) ($order->getUid() ?? ''),
            'order_link' => $orderLink,
            'cancellation_reason' => $order->getCancelComment() ?? '(geen reden opgegeven)',
            'customer_name' => $customerName,
            'customer_first_name' => $customerName,
            'first_name' => $firstName,
            'main_number' => $mainNumber,
            'quote_number' => $quoteNumber,
        ];
    }

    public static function preview(): static
    {
        return new static(Quote::resolveForEmailPreview());
    }

    public function build(): self
    {
        $mail = $this
            ->view('emails.template-content', [
                'content' => $this->getTemplateContent(),
            ])
            ->subject($this->getTemplateSubject());

        [$email, $name] = $this->order->getBillingRecipient();
        if ($email !== null && $email !== '') {
            $this->to($email, $name);
        }

        $this->applyTemplateRecipients();

        return $mail;
    }
}
