<?php

namespace App\Mail;

use App\Actions\SendInvoiceMailAction;
use App\Jobs\SendDepositInvoiceMailJob;
use App\Mail\Concerns\BuildsQuotePdfDownloadButton;
use App\Models\Order\BaseOrder;
use App\Models\Order\DepositInvoice;
use App\Models\Order\Order;
use Throwable;
use App\Mail\Traits\HasTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DepositInvoiceMail extends Mailable
{
    use BuildsQuotePdfDownloadButton;
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

    /** @return array<string, string> */
    public function getTemplateVars(): array
    {
        $deposit = $this->resolveDepositInvoice();
        $invoiceNumber = $deposit?->getUidFormatted() ?? $this->order->getUidFormatted() ?? '';
        $customerName = $this->order->customer?->getName()
            ?? $this->order->billingCustomer?->getName()
            ?? '';
        $firstName = $this->order->customer?->getFirstName()
            ?? $this->order->billingCustomer?->getFirstName()
            ?? '';

        return [
            'customer_first_name' => $customerName,
            'first_name' => $firstName,
            'invoice_number' => $invoiceNumber,
            'deposit_invoice_number' => $invoiceNumber,
            'main_number' => $this->order->main?->getUidFormatted() ?? '',
            'invoice_download_button' => $deposit instanceof DepositInvoice
                ? $this->quotePdfDownloadButton(
                    'quote.public.invoice-pdf',
                    (string) ($deposit->public_download_uuid ?? ''),
                    'Aanbetalingsfactuur downloaden',
                )
                : '',
        ];
    }

    protected function resolveDepositInvoice(): ?DepositInvoice
    {
        $this->order->loadMissing(['depositInvoice', 'main.depositInvoice']);

        if ($this->order->depositInvoice instanceof DepositInvoice) {
            return $this->order->depositInvoice;
        }

        $deposit = $this->order->main?->depositInvoice;
        if ($deposit instanceof DepositInvoice) {
            return $deposit;
        }

        if ($this->order->main_id === null) {
            return null;
        }

        $deposit = DepositInvoice::query()
            ->where('main_id', $this->order->main_id)
            ->first();

        return $deposit instanceof DepositInvoice ? $deposit : null;
    }

    public static function preview(): static
    {
        $order = Order::query()
            ->whereNotNull('deposit_invoice_id')
            ->whereHas('main')
            ->latest()
            ->first();

        if (! $order instanceof Order) {
            $deposit = DepositInvoice::query()
                ->whereNotNull('main_id')
                ->latest()
                ->first();

            if ($deposit instanceof DepositInvoice) {
                $order = Order::withoutGlobalScopes()
                    ->where('main_id', $deposit->main_id)
                    ->latest()
                    ->first();
            }
        }

        if ($order instanceof Order) {
            $order = SendDepositInvoiceMailJob::resolveOrderForDepositMail($order) ?? $order;
        }

        if (! $order instanceof Order) {
            $order = Order::query()->whereHas('main')->latest()->first();
        }

        if ($order === null) {
            throw new \RuntimeException('No order found for DepositInvoiceMail::preview().');
        }

        $mailable = new static($order);
        $deposit = $mailable->resolveDepositInvoice();

        if ($deposit instanceof DepositInvoice) {
            $deposit->getOrCreatePublicDownloadUuid();
            $order->setRelation('depositInvoice', $deposit);
        }

        return new static($order);
    }

    public function build(): self
    {
        $mail = $this
            ->view('emails.template-content', [
                'content' => $this->getTemplateContent(),
            ])
            ->subject($this->getTemplateSubject());

        SendInvoiceMailAction::applyInvoiceMailToCcToMailable($mail, $this->order);

        $this->applyTemplateRecipients();

        return $mail;
    }
}
