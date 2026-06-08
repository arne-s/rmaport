<?php

namespace App\Mail;

use App\Helpers\EmailHelper;
use App\Mail\Traits\HasTemplate;
use App\Models\EmailTemplate;
use App\Models\MailSenderProfile;
use App\Models\Order\Main;
use App\Models\OrderProduct;
use App\Models\ProductStock;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class LowStockAlertMail extends Mailable
{
    use HasTemplate, Queueable, SerializesModels;

    public function __construct(
        public ProductStock $stock,
        public ?OrderProduct $orderProduct = null,
    ) {}

    /**
     * Whether the DB template has at least one deliverable To/Cc/Bcc recipient (mirrors {@see HasTemplate::applyTemplateRecipients}).
     */
    public static function hasConfiguredRecipients(): bool
    {
        $template = EmailTemplate::query()
            ->where('class', self::class)
            ->first();

        if ($template === null) {
            return false;
        }

        foreach ($template->getUsersTo() as $user) {
            if (EmailHelper::isValid($user->getEmail())) {
                return true;
            }
        }

        foreach ($template->getUsersCc() as $user) {
            if (EmailHelper::isValid($user->getEmail())) {
                return true;
            }
        }

        foreach ($template->getUsersBcc() as $user) {
            if (EmailHelper::isValid($user->getEmail())) {
                return true;
            }
        }

        if (filled($template->cc_sender_profile_uid)) {
            $email = MailSenderProfile::query()
                ->where('uid', $template->cc_sender_profile_uid)
                ->with('microsoftMailToken')
                ->first()
                ?->microsoftMailToken
                ?->microsoft_email;

            if (EmailHelper::isValid($email)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public function getTemplateVars(): array
    {
        $product = $this->stock->product;
        $productId = $product?->getId() ?? 0;

        $main = $this->resolveMain();
        $mainViewUrl = $main !== null
            ? route('filament.app.resources.mains.view', ['record' => $main->getId()], true)
            : '';

        return [
            'product_name' => $product?->getName() ?? '',
            'product_link' => $productId > 0
                ? route('filament.app.resources.products.edit', ['record' => $productId], true)
                : '',
            'product_physical_stock' => (string) $this->stock->getPhysicalStock(),
            'product_min_threshold' => (string) $this->stock->getMinThreshold(),
            'order_link' => $mainViewUrl,
            'main_number' => $main?->getUid() ?? '',
        ];
    }

    /**
     * @throws Throwable
     */
    public function build(): self
    {
        $this
            ->view('emails.template-content', [
                'content' => $this->getTemplateContent(),
            ])
            ->subject($this->getTemplateSubject());

        $this->applyTemplateRecipients();

        return $this;
    }

    public function allowOverrideTo(): bool
    {
        return true;
    }

    public static function preview(): self
    {
        $stock = ProductStock::query()
            ->with('product')
            ->latest()
            ->first();

        if ($stock === null) {
            $stock = new ProductStock([
                'physical_stock' => 2,
                'reserved_stock' => 0,
                'min_threshold' => 5,
                'allow_backorder' => true,
            ]);
        }

        return new self($stock);
    }

    private function resolveMain(): ?Main
    {
        if ($this->orderProduct === null) {
            return null;
        }

        $order = $this->orderProduct->order;

        if ($order instanceof Main) {
            return $order;
        }

        return $order?->getMain();
    }
}
