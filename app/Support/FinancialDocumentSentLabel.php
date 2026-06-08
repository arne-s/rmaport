<?php

namespace App\Support;

use App\Enums\OrderType;
use App\Mail\DepositInvoiceMail;
use App\Mail\InvoiceMail;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\OrderEvent;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final class FinancialDocumentSentLabel
{
    /**
     * @return array{label: string, scheduled_at: ?CarbonInterface}
     */
    public static function resolve(BaseOrder $order): array
    {
        $sentAt = $order->getSentAt();
        if ($sentAt !== null) {
            return [
                'label' => $sentAt->translatedFormat('j M Y'),
                'scheduled_at' => null,
            ];
        }

        $scheduledAt = self::resolveScheduledMailAt($order);
        if ($scheduledAt === null || ! $scheduledAt->isFuture()) {
            return [
                'label' => '-',
                'scheduled_at' => null,
            ];
        }

        $seconds = max(0, $scheduledAt->getTimestamp() - now()->getTimestamp());

        return [
            'label' => 'Over ' . DurationTime::secondsToRoundedHoursLabel($seconds),
            'scheduled_at' => $scheduledAt,
        ];
    }

    public static function resolveScheduledMailAt(BaseOrder $order): ?Carbon
    {
        $typeValue = $order->getType() instanceof OrderType
            ? $order->getType()->value
            : (string) ($order->type ?? '');

        $mailableClass = match ($typeValue) {
            OrderType::Invoice->value => InvoiceMail::class,
            OrderType::DepositInvoice->value => DepositInvoiceMail::class,
            default => null,
        };

        if ($mailableClass === null) {
            return null;
        }

        $fromEvent = self::scheduledAtFromMailEvents($order, $mailableClass);
        if ($fromEvent !== null) {
            return $fromEvent;
        }

        $delaySeconds = self::resolveMailDelaySeconds($order, $typeValue);
        if ($delaySeconds < 1) {
            return null;
        }

        $anchor = $order->created_at ?? $order->updated_at;
        if ($anchor === null) {
            return null;
        }

        return $anchor->copy()->addSeconds($delaySeconds);
    }

    private static function scheduledAtFromMailEvents(BaseOrder $order, string $mailableClass): ?Carbon
    {
        $events = self::mailScheduleEventsFor($order);

        $latest = null;

        foreach ($events as $event) {
            if (($event->data['mailable_class'] ?? '') !== $mailableClass) {
                continue;
            }

            $scheduledAt = $event->data['scheduled_at'] ?? null;
            if (! is_string($scheduledAt) || $scheduledAt === '') {
                continue;
            }

            $parsed = Carbon::parse($scheduledAt);
            if ($latest === null || $parsed->greaterThan($latest)) {
                $latest = $parsed;
            }
        }

        return $latest;
    }

    /**
     * @return Collection<int, OrderEvent>
     */
    private static function mailScheduleEventsFor(BaseOrder $order): Collection
    {
        if ($order->relationLoaded('orderEvents')) {
            return $order->orderEvents
                ->filter(fn (OrderEvent $event): bool => str_starts_with((string) $event->type, 'E-mail gepland'));
        }

        $main = $order->main;
        if ($main instanceof Main) {
            if ($main->relationLoaded('orderEvents')) {
                return $main->orderEvents
                    ->filter(fn (OrderEvent $event): bool => str_starts_with((string) $event->type, 'E-mail gepland'));
            }

            return $main->orderEvents()
                ->where('type', 'like', 'E-mail gepland%')
                ->orderByDesc('id')
                ->get();
        }

        return $order->orderEvents()
            ->where('type', 'like', 'E-mail gepland%')
            ->orderByDesc('id')
            ->get();
    }

    private static function resolveMailDelaySeconds(BaseOrder $order, string $typeValue): int
    {
        $main = $order->main;
        if ($main instanceof Main) {
            if ($typeValue === OrderType::Invoice->value) {
                return $main->invoiceMailDelaySecondsForDispatch();
            }
        }

        if ($typeValue === OrderType::DepositInvoice->value) {
            return max(0, (int) Setting::get('mail.deposit_invoice_mail_delay_seconds', 4 * 3600));
        }

        return max(0, (int) Setting::get('mail.invoice_mail_delay_seconds'));
    }
}
