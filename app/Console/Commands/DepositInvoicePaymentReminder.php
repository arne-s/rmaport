<?php

namespace App\Console\Commands;

use App\Enums\OrderGeneralStatus;
use App\Mail\DepositInvoiceReminderMail;
use App\Models\Order\DepositInvoice;
use App\Support\InvoiceReminderSettings;
use Throwable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class DepositInvoicePaymentReminder extends Command
{
    protected $signature = 'app:deposit-payment-reminder';

    protected $description = 'Looks up all deposit invoices that are not paid and sends reminders.';

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $invoices = DepositInvoice::where('expires_at', '>', now()->format('Y-m-d'))
            ->whereNotNull('sent_at')
            ->whereNotNull('exact_synced_at')
            ->whereNull('paid_at')
            ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value])
            ->get();

        $count = 0;

        foreach ($invoices as $invoice) {
            if (! InvoiceReminderSettings::isEnabledForOrder($invoice)) {
                continue;
            }

            $days = abs((int) now()->diffInDays($invoice->getSentAt()));

            if ($days === 0) {
                $this->sendReminderMail($invoice, 1);
                $count++;
            }

            if ($days === 13) {
                $this->sendReminderMail($invoice, 2);
                $count++;
            }
        }

        $this->info($count . ' reminders sent.');
    }

    /**
     * @throws Throwable
     */
    protected function sendReminderMail(DepositInvoice $invoice, int $nr): void
    {
        $recipientEmail = $invoice->billingCustomer?->getEmail() ?? $invoice->customer?->getEmail() ?? '';
        $this->info('Send reminder for invoice ' . $invoice->getUid() . ' to ' . $recipientEmail . ' (#' . $nr . ').');

        Mail::to($recipientEmail)->send(
            new DepositInvoiceReminderMail($invoice, $nr)
        );
    }
}
