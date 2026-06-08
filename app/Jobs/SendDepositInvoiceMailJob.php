<?php

namespace App\Jobs;

use App\Actions\OrderMailEventLogger;
use App\Actions\SendDepositInvoiceMailAction;
use App\Mail\DepositInvoiceMail;
use App\Models\Order\DepositInvoice;
use App\Models\Order\Order;
use App\Models\Setting;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendDepositInvoiceMailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $orderId) {}

    public static function dispatchDelayedForOrder(int $orderId): PendingDispatch
    {
        $seconds = self::depositMailDelaySeconds();

        Log::info('SendDepositInvoiceMailJob: dispatching', [
            'order_id' => $orderId,
            'delay_seconds' => $seconds,
        ]);

        if ($seconds > 0) {
            self::logScheduledDepositInvoiceMail($orderId, $seconds);
        }

        return self::dispatch($orderId)->delay(now()->addSeconds($seconds));
    }

    private static function logScheduledDepositInvoiceMail(int $orderId, int $delaySeconds): void
    {
        $order = Order::withoutGlobalScopes()->find($orderId);
        if ($order === null) {
            return;
        }

        app(OrderMailEventLogger::class)->logScheduled(
            $order,
            DepositInvoiceMail::class,
            $delaySeconds,
            now()->addSeconds($delaySeconds),
        );
    }

    /**
     * Maximum delay window for the unique job lock; must exceed configured deposit-mail delay.
     */
    public function uniqueFor(): int
    {
        return self::depositMailDelaySeconds() + 3600;
    }

    private static function depositMailDelaySeconds(): int
    {
        return max(0, (int) Setting::get('mail.deposit_invoice_mail_delay_seconds', 4 * 3600));
    }

    public function uniqueId(): string
    {
        return 'send-deposit-invoice-mail-' . $this->orderId;
    }

    public function handle(SendDepositInvoiceMailAction $action): void
    {
        Log::info('SendDepositInvoiceMailJob: start', [
            'order_id' => $this->orderId,
        ]);

        $order = Order::withoutGlobalScopes()->find($this->orderId);
        if ($order === null) {
            Log::warning('SendDepositInvoiceMailJob: order not found', [
                'order_id' => $this->orderId,
            ]);

            return;
        }

        $orderForMail = self::resolveOrderForDepositMail($order);
        if ($orderForMail === null) {
            Log::warning('SendDepositInvoiceMailJob: no deposit invoice found for order', [
                'order_id' => $this->orderId,
                'main_id' => $order->main_id,
            ]);

            return;
        }

        $action->execute($orderForMail);

        Log::info('SendDepositInvoiceMailJob: completed', [
            'order_id' => $orderForMail->getId(),
            'deposit_invoice_id' => $orderForMail->depositInvoice?->getId(),
        ]);
    }

    /**
     * Resolve the order row whose {@see Order::depositInvoice} relation can be used for mailing.
     * Falls back to main-linked deposit invoice when the dispatched order row has no deposit_invoice_id.
     */
    public static function resolveOrderForDepositMail(Order $order): ?Order
    {
        $order->loadMissing(['depositInvoice', 'main.depositInvoice']);

        if ($order->depositInvoice !== null) {
            return $order;
        }

        $deposit = $order->main?->depositInvoice;
        if (! $deposit instanceof DepositInvoice && $order->main_id !== null) {
            $deposit = DepositInvoice::query()
                ->where('main_id', $order->main_id)
                ->first();
        }

        if (! $deposit instanceof DepositInvoice) {
            return null;
        }

        $linkedOrder = Order::withoutGlobalScopes()
            ->where('deposit_invoice_id', $deposit->getId())
            ->first();

        if ($linkedOrder instanceof Order) {
            return $linkedOrder;
        }

        $order->setRelation('depositInvoice', $deposit);

        return $order;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('SendDepositInvoiceMailJob failed for order ' . $this->orderId . ': ' . ($exception?->getMessage() ?? 'unknown'));
    }
}
