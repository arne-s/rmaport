<?php

namespace App\Console\Commands\Unit;

use App\Actions\SendVerifyCustomerPaymentMailAction;
use App\Enums\AppointmentType;
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Models\Order\Main;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendVerifyCustomerPaymentMailsCommand extends Command
{
    protected $signature = 'unit:send-verify-customer-payment-mails';

    protected $description = 'Send internal verify-payment mail once per unit main while a delivery is between configured min/max hours away and invoices remain unpaid.';

    public function handle(SendVerifyCustomerPaymentMailAction $sendAction): int
    {
        $minHours = max(1, (int) Setting::get('mail.payment_verify_min_hours_until_delivery', 6));
        $maxHours = max(1, (int) Setting::get('mail.payment_verify_hours_before_delivery', 48));
        if ($maxHours <= $minHours) {
            $maxHours = $minHours + 1;
        }

        $rangeStart = now()->copy()->addHours($minHours);
        $rangeEnd = now()->copy()->addHours($maxHours);

        $candidateIds = Main::query()
            ->where('subtype', OrderSubtype::Unit)
            ->whereNull('payment_verify_mail_sent_at')
            ->whereNotIn('order_status', [
                OrderStatus::Cancelled,
                OrderStatus::FittingCancelled,
                OrderStatus::QuoteCancelled,
            ])
            ->whereHas('appointments', function (Builder $q) use ($rangeStart, $rangeEnd): void {
                $q->where('type', AppointmentType::Delivery)
                    ->where('is_active', true)
                    ->whereBetween('datetime', [$rangeStart, $rangeEnd]);
            })
            ->pluck('id');

        $sent = 0;

        foreach ($candidateIds as $mainId) {
            try {
                $dispatched = DB::transaction(function () use ($mainId, $sendAction, $rangeStart, $rangeEnd): bool {
                    $main = Main::query()->whereKey($mainId)->lockForUpdate()->first();
                    if (! $main instanceof Main) {
                        return false;
                    }

                    if ($main->payment_verify_mail_sent_at !== null) {
                        return false;
                    }

                    if (! $main->hasUnpaidIssuedBillableInvoices()) {
                        return false;
                    }

                    $inRange = $main->appointments()
                        ->where('type', AppointmentType::Delivery)
                        ->where('is_active', true)
                        ->whereBetween('datetime', [$rangeStart, $rangeEnd])
                        ->exists();

                    if (! $inRange) {
                        return false;
                    }

                    if (! $sendAction->execute($main)) {
                        return false;
                    }

                    $main->forceFill([
                        'payment_verify_mail_sent_at' => now(),
                    ])->save();

                    return true;
                });

                if ($dispatched) {
                    $sent++;
                }
            } catch (Throwable $e) {
                report($e);
                $this->error('Verify payment mail failed for main_id ' . $mainId . ': ' . $e->getMessage());
            }
        }

        $this->info("Payment verify mails sent: {$sent}.");

        return self::SUCCESS;
    }
}
