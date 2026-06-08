<?php

namespace App\Mail\Service;

use App\Enums\OrderSubtype;
use App\Enums\OrderType;
use App\Helpers\EmailHelper;
use App\Mail\Concerns\BuildsQuotePdfDownloadButton;
use App\Mail\Concerns\ResolvesOrderCustomerAsTemplateRecipient;
use App\Mail\Traits\HasTemplate;
use App\Models\Order\BaseOrder;
use App\Models\Order\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class OrderConfirmMail extends Mailable
{
    use BuildsQuotePdfDownloadButton;
    use HasTemplate, Queueable, ResolvesOrderCustomerAsTemplateRecipient, SerializesModels {
        ResolvesOrderCustomerAsTemplateRecipient::getTemplateRecipientVars insteadof HasTemplate;
    }

    public BaseOrder $order;
    public ?string $subjectOverride = null;
    public ?string $messageOverride = null;

    /**
     * @var array<int, array{path: string, name: string, mime: string}>
     */
    private array $attachmentFiles = [];

    /**
     * @throws Throwable
     */
    public function __construct(
        BaseOrder $order,
        ?string $subjectOverride = null,
        ?string $messageOverride = null,
        array $attachments = [],
    ) {
        $this->order = $order;
        $this->subjectOverride = $subjectOverride;
        $this->messageOverride = $messageOverride;
        $this->attachmentFiles = $attachments;
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
        $this->order->loadMissing(['customer', 'billingCustomer', 'main']);

        $customerName = $this->order->customer?->getName()
            ?? $this->order->billingCustomer?->getName()
            ?? '';
        $firstName = $this->order->customer?->getFirstName()
            ?? $this->order->billingCustomer?->getFirstName()
            ?? '';

        $orderDownloadButton = '';
        if ($this->order instanceof Order && $this->order->getType() === OrderType::Order) {
            $orderDownloadButton = $this->quotePdfDownloadButton(
                'quote.public.order-pdf',
                (string) ($this->order->public_download_uuid ?? ''),
                'Orderbevestiging downloaden',
            );
        }

        return [
            'customer_first_name' => $customerName,
            'first_name' => $firstName,
            'main_number' => $this->order->main?->getUidFormatted(),
            'order_number' => (string) ($this->order->getUid() ?? ''),
            'order_download_button' => $orderDownloadButton,
        ];
    }

    public static function preview(): static
    {
        return new static(Order::resolveForEmailPreview(OrderSubtype::Service));
    }

    public function build(): self
    {
        $content = $this->messageOverride !== null && $this->messageOverride !== ''
            ? $this->parseTemplateString($this->messageOverride)
            : $this->getTemplateContent();
        $subject = $this->subjectOverride !== null && $this->subjectOverride !== ''
            ? $this->parseTemplateString($this->subjectOverride)
            : $this->getTemplateSubject();

        $mail = $this
            ->view('emails.template-content', [
                'content' => $content,
            ])
            ->subject($subject);

        foreach ($this->attachmentFiles as $attachment) {
            $mail->attach($attachment['path'], [
                'as' => $attachment['name'],
                'mime' => $attachment['mime'],
            ]);
        }

        $customer = $this->order->customer;
        $primaryToEmail = null;
        if ($customer !== null) {
            $primaryToEmail = $customer->getEmail();
            if (EmailHelper::isValid($primaryToEmail)) {
                $mail->to($primaryToEmail, $customer->getName() ?? '');
            }
        }

        $company = $this->order->billingCustomer;
        if ($company !== null) {
            $companyEmail = $company->getEmail();
            if (
                EmailHelper::isValid($companyEmail)
                && ! EmailHelper::billingCcDuplicatesPrimaryRecipient($customer, $company, $primaryToEmail)
            ) {
                $mail->cc($companyEmail, $company->getName() ?? '');
            }
        }

        $this->applyTemplateRecipients();

        return $mail;
    }
}
