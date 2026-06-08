<?php

namespace App\Console\Commands\ExactOnline;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderType;
use App\Models\Order\BaseOrder;
use App\Services\ExactOnlineService;
use Illuminate\Console\Command;

class SyncPayments extends Command
{
    protected $signature = 'exact-online:sync-payments';

    protected $description = 'Sync sales payment status from Exact Online';

    public function handle(): int
    {
        /** @var ExactOnlineService $exact */
        $exact = app('exact');

        return $this->syncSalesPayments($exact) ? 0 : 1;
    }

    public function syncSalesPayments(ExactOnlineService $exact): bool
    {
        $unpaidInvoices = BaseOrder::query()
            ->whereNotNull('exact_id')
            ->whereNotNull('uid')
            ->whereNull('paid_at')
            ->whereNotIn('type', [OrderType::Order->value, OrderType::Quote->value])
            ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value])
            ->get(['id', 'type', 'uid', 'rev', 'exact_id']);

        $uids = $unpaidInvoices->pluck('uid')->filter()->values()->all();

        $paidInvoices = $exact->getPaidSalesInvoices($uids);

        if (is_array($paidInvoices) && empty($paidInvoices)) {
            $this->info('No paid sales invoices found.');

            return true;
        }

        if (is_null($paidInvoices)) {
            $this->error('Error fetching paid sales invoices from Exact Online.');

            return false;
        }

        /** @var array<string, array<string, mixed>> $paidByUid keyed by Description (= invoice uid) */
        $paidByUid = [];
        foreach ($paidInvoices as $row) {
            if (isset($row['Description'])) {
                $paidByUid[(string) $row['Description']] = $row;
            }
        }

        foreach ($unpaidInvoices as $invoice) {
            if (! isset($paidByUid[(string) $invoice->uid])) {
                continue;
            }

            $row = $paidByUid[(string) $invoice->uid];
            $date = $exact->parseDotNetDate($row['LastPaymentDate']) ?? now();

            $invoice->paid_at = $date;
            $invoice->status = OrderGeneralStatus::Paid;
            $invoice->payment_method = \App\Enums\PaymentMethodType::ExactBank;
            $invoice->save();

            $uid = $invoice->getUidFormatted() ?: $invoice->uid;
            $this->info("Payment received via Exact for invoice {$uid}.");

            if ($invoice->getType() === OrderType::DepositInvoice) {
                $order = $invoice->order;
                if ($order) {
                    $order->setIsVerified(true);
                    $order->save();
                    $this->info("Order #{$order->getUidFormatted()} verified due to deposit invoice payment.");
                }
            }
        }

        return true;
    }
}
