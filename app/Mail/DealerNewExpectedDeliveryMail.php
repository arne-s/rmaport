<?php

namespace App\Mail;

use App\Mail\Concerns\BuildsDealerExpectedDeliveryTemplateVars;
use App\Mail\Traits\HasTemplate;
use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DealerNewExpectedDeliveryMail extends Mailable
{
    use BuildsDealerExpectedDeliveryTemplateVars;
    use HasTemplate;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public PurchaseOrder $purchaseOrder,
        public string $deliveryDate,
    ) {}

    /**
     * @return array<string, string>
     */
    public function getTemplateVars(): array
    {
        return $this->buildDealerExpectedDeliveryTemplateVars($this->purchaseOrder, $this->deliveryDate);
    }

    public function build(): static
    {
        return $this
            ->view('emails.template-content', [
                'content' => $this->getTemplateContent(),
            ])
            ->subject($this->getTemplateSubject())
            ->from(config('mail.from.address'), config('mail.from.name'));
    }
}
