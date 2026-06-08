<?php

namespace App\Console\Commands\Invoices;

use App\Actions\SendInvoiceFirstReminderMailAction;
use App\Actions\SendInvoiceSecondReminderMailAction;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderType;
use App\Models\Order\Invoice;
use App\Models\Setting;
use App\Support\InvoiceReminderSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendPaymentRemindersCommand extends Command
{
    protected $signature = 'invoices:send-payment-reminders {--diagnose : Toon per factuur waarom wel/niet in aanmerking voor 1e of 2e herinnering (stuurt geen mail)}';

    protected $description = 'Send first and second payment reminder e-mails for overdue unpaid slot and deposit invoices.';

    public function handle(): int
    {
        $daysAfterDue = max(0, (int) Setting::get('mail.invoice_first_reminder_days_after_due', 0));
        $daysAfterFirst = max(0, (int) Setting::get('mail.invoice_second_reminder_days_after_first', 7));

        if ($this->option('diagnose')) {
            return $this->runDiagnosis($daysAfterDue, $daysAfterFirst);
        }

        $firstCount = 0;
        $secondCount = 0;

        $firstCutoff = now()->subDays($daysAfterDue);

        $firstIds = $this->baseBillableInvoiceQuery()
            ->whereNull('paid_at')
            ->whereNull('credit_invoice_id')
            ->where('is_test', 0)
            ->whereNotNull('sent_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $firstCutoff)
            ->whereNull('first_reminder_sent_at')
            ->pluck('id');

        foreach ($firstIds as $id) {
            try {
                $sent = DB::transaction(function () use ($id, $daysAfterDue): bool {
                    $invoice = Invoice::withoutGlobalScopes()
                        ->whereIn('type', $this->billableInvoiceTypes())
                        ->whereKey($id)
                        ->lockForUpdate()
                        ->first();

                    if ($invoice === null || ! $this->isEligibleForFirstReminder($invoice, $daysAfterDue)) {
                        return false;
                    }

                    if (! InvoiceReminderSettings::isEnabledForOrder($invoice)) {
                        return false;
                    }

                    (new SendInvoiceFirstReminderMailAction($invoice))->execute();

                    return true;
                });
                if ($sent) {
                    $firstCount++;
                }
            } catch (Throwable $e) {
                report($e);
                $this->error('First reminder failed for invoice id ' . $id . ': ' . $e->getMessage());
            }
        }

        $secondIds = $this->baseBillableInvoiceQuery()
            ->whereNull('paid_at')
            ->whereNull('credit_invoice_id')
            ->where('is_test', 0)
            ->whereNotNull('first_reminder_sent_at')
            ->whereNull('second_reminder_sent_at')
            ->where('first_reminder_sent_at', '<=', now()->subDays($daysAfterFirst))
            ->pluck('id');

        foreach ($secondIds as $id) {
            try {
                $sent = DB::transaction(function () use ($id, $daysAfterFirst): bool {
                    $invoice = Invoice::withoutGlobalScopes()
                        ->whereIn('type', $this->billableInvoiceTypes())
                        ->whereKey($id)
                        ->lockForUpdate()
                        ->first();

                    if ($invoice === null || ! $this->isEligibleForSecondReminder($invoice, $daysAfterFirst)) {
                        return false;
                    }

                    if (! InvoiceReminderSettings::isEnabledForOrder($invoice)) {
                        return false;
                    }

                    (new SendInvoiceSecondReminderMailAction($invoice))->execute();

                    return true;
                });
                if ($sent) {
                    $secondCount++;
                }
            } catch (Throwable $e) {
                report($e);
                $this->error('Second reminder failed for invoice id ' . $id . ': ' . $e->getMessage());
            }
        }

        $this->info("Payment reminders: {$firstCount} first, {$secondCount} second.");

        return self::SUCCESS;
    }

    private function runDiagnosis(int $daysAfterDue, int $daysAfterFirst): int
    {
        $this->info('Diagnose (geen mail verstuurd). Eerste herinnering '.$daysAfterDue.' dagen na vervaldatum; tweede herinnering na '.$daysAfterFirst.' dagen na de eerste.');

        $rows = $this->baseBillableInvoiceQuery()
            ->whereNull('paid_at')
            ->where('is_test', 0)
            ->whereNotNull('sent_at')
            ->orderBy('id')
            ->get(['id', 'type', 'uid', 'status', 'paid_at', 'credit_invoice_id', 'sent_at', 'expires_at', 'first_reminder_sent_at', 'second_reminder_sent_at']);

        if ($rows->isEmpty()) {
            $this->warn('Geen slot- of aanbetalingsfacturen (niet concept/geannuleerd/initial).');

            return self::SUCCESS;
        }

        $now = now();
        $firstCutoff = $now->copy()->subDays($daysAfterDue);
        $table = [];

        foreach ($rows as $row) {
            $invoice = Invoice::withoutGlobalScopes()->find($row->id);
            $segment = $invoice !== null ? $invoice->getBillingCustomerSegmentKey() : '—';
            $remindersEnabled = $invoice !== null && InvoiceReminderSettings::isEnabledForOrder($invoice);

            $creditBlocked = $row->credit_invoice_id !== null;
            $expiresAt = $row->expires_at;
            $expiresPast = $expiresAt !== null && $expiresAt->lte($firstCutoff);
            $expiresOk = $expiresAt !== null && $expiresPast;
            $firstAt = $row->first_reminder_sent_at;
            $secondAt = $row->second_reminder_sent_at;

            $firstEligible = $remindersEnabled
                && ! $creditBlocked
                && $expiresOk
                && $firstAt === null;

            $secondEligible = $remindersEnabled
                && ! $creditBlocked
                && $firstAt !== null
                && $secondAt === null
                && $firstAt->lessThanOrEqualTo($now->copy()->subDays($daysAfterFirst));

            $notes = [];
            if (! $remindersEnabled) {
                $notes[] = 'herinneringen uitgeschakeld voor segment ' . $segment;
            }
            if ($creditBlocked) {
                $notes[] = 'credit_invoice_id='.$row->credit_invoice_id;
            }
            if ($row->paid_at !== null) {
                $notes[] = 'betaald';
            }
            if ($firstAt !== null) {
                $notes[] = '1e al '.$firstAt->toDateTimeString();
            }
            if ($expiresAt === null) {
                $notes[] = 'expires_at leeg';
            } elseif ($expiresAt->gt($now)) {
                $notes[] = 'expires_at nog niet verstreken';
            } elseif ($daysAfterDue > 0 && $expiresAt->gt($firstCutoff)) {
                $notes[] = '1e pas vanaf '.$expiresAt->copy()->addDays($daysAfterDue)->toDateTimeString();
            }
            if ($secondAt !== null) {
                $notes[] = '2e al '.$secondAt->toDateTimeString();
            }
            if ($firstAt !== null && $secondAt === null && ! $secondEligible && ! $creditBlocked) {
                $notes[] = '2e pas vanaf '. $firstAt->copy()->addDays($daysAfterFirst)->toDateTimeString();
            }

            $typeLabel = $row->getType() instanceof OrderType
                ? $row->getType()->value
                : (string) $row->getAttribute('type');

            $table[] = [
                'id' => (string) $row->id,
                'type' => $typeLabel,
                'uid' => (string) ($row->uid ?? ''),
                'segment' => $segment,
                '1e nu' => $firstEligible ? 'ja' : 'nee',
                '2e nu' => $secondEligible ? 'ja' : 'nee',
                'toelichting' => implode('; ', $notes) ?: '—',
            ];
        }

        $this->table(array_keys($table[0]), $table);

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Invoice>
     */
    private function baseBillableInvoiceQuery()
    {
        return Invoice::withoutGlobalScopes()
            ->whereIn('type', $this->billableInvoiceTypes())
            ->whereNotIn('status', [
                OrderGeneralStatus::Initial->value,
                OrderGeneralStatus::Draft->value,
                OrderGeneralStatus::Cancelled->value,
            ]);
    }

    /**
     * @return list<OrderType>
     */
    private function billableInvoiceTypes(): array
    {
        return [OrderType::Invoice, OrderType::DepositInvoice];
    }

    private function isEligibleForFirstReminder(Invoice $invoice, int $daysAfterDue): bool
    {
        if (! in_array($invoice->getType(), $this->billableInvoiceTypes(), true)) {
            return false;
        }

        if ($invoice->isInvoicePaid()) {
            return false;
        }

        if ($invoice->getCreditInvoiceId() !== null) {
            return false;
        }

        if ((int) $invoice->getIsTest() !== 0) {
            return false;
        }

        if ($invoice->getSentAt() === null) {
            return false;
        }

        $expiresAt = $invoice->getExpiresAt();
        if ($expiresAt === null || $expiresAt->gt(now()->subDays($daysAfterDue))) {
            return false;
        }

        if (! InvoiceReminderSettings::isEnabledForOrder($invoice)) {
            return false;
        }

        return $invoice->getFirstReminderSentAt() === null;
    }

    private function isEligibleForSecondReminder(Invoice $invoice, int $daysAfterFirst): bool
    {
        if (! in_array($invoice->getType(), $this->billableInvoiceTypes(), true)) {
            return false;
        }

        if ($invoice->isInvoicePaid() || $invoice->getCreditInvoiceId() !== null || (int) $invoice->getIsTest() !== 0) {
            return false;
        }

        $firstAt = $invoice->getFirstReminderSentAt();
        if ($firstAt === null || $invoice->getSecondReminderSentAt() !== null) {
            return false;
        }

        if (! InvoiceReminderSettings::isEnabledForOrder($invoice)) {
            return false;
        }

        return $firstAt->lessThanOrEqualTo(now()->subDays($daysAfterFirst));
    }
}
