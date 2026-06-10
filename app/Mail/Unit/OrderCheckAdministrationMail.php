<?php

namespace App\Mail\Unit;

use App\Enums\CustomerType;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Mail\Traits\HasTemplate;
use App\Models\Order\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class OrderCheckAdministrationMail extends Mailable
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
        $main = $this->order instanceof Main
            ? $this->order
            : $this->order->getMain();

        $record = $main ?? $this->order;
        $record->loadMissing(['customer', 'billingCustomer']);

        if ($record->billingCustomer?->getType() === CustomerType::B2B) {
            $attention = $record->resolveDealerMailSalutation();
            $customerFirstName = $attention;
            $customerName = $attention;
        } else {
            $customerFirstName = $record->customer?->getFirstName()
                ?? $record->billingCustomer?->getFirstName()
                ?? '';
            $customerName = $record->customer?->getName()
                ?? $record->billingCustomer?->getName()
                ?? '';
        }

        // Use the order model to get the order because order is still concept and not saved as relation of main
        $order = $main !== null
            ? Order::query()->where('main_id', $main->getId())->latest()->first()
            : null;

        $orderLink = route('filament.app.resources.mains.view', [
            'record' => $main?->getId() ?? $this->order->getId(),
        ], true);

        return [
            'customer_first_name' => $customerFirstName,
            'customer_name' => $customerName,
            'order_number' => $order?->getUid() ?? '(nog geen ordernummer)',
            'order_link' => $orderLink,
            'main_number' => $main?->getUid() ?? '',
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

        $this->applyFallbackRecipients();

        return $mail;
    }

    /**
     * Add a fallback recipient when the email template has no To/Cc/Bcc.
     */
    protected function applyFallbackRecipients(): void
    {
        $this->initTemplate();
        $template = $this->template;
        $hasRecipients = $template->getUsersTo()->isNotEmpty()
            || $template->getUsersCc()->isNotEmpty()
            || $template->getUsersBcc()->isNotEmpty();

        if (! $hasRecipients) {
            $fallback = config('mail.order_check_administration_fallback');
            if (filled($fallback)) {
                $this->to($fallback);
            }
        }
    }
}
