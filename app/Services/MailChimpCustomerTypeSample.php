<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\NewsletterSubscription;
use Illuminate\Database\Eloquent\Builder;

final class MailChimpCustomerTypeSample
{
    /**
     * @return array{
     *     subscription_ids: list<int>,
     *     summary: array<string, array{customers: int, subscriptions: int, segments: string}>
     * }
     */
    public static function resolve(int $perType): array
    {
        $subscriptionIds = [];
        $summary = [];

        foreach (CustomerType::cases() as $type) {
            if (! $type->isVisible()) {
                continue;
            }

            $primarySegment = $type->billingNewsletterSegment();
            if ($primarySegment === null) {
                continue;
            }

            $label = $type->getLabel() ?? $type->value;
            $shippingSegment = $type->shippingNewsletterSegment();

            $customers = Customer::query()
                ->with(['billingAddress', 'shippingAddress'])
                ->where('type', $type)
                ->where('status', CustomerStatus::Active)
                ->whereHas('newsletterSubscriptions', function (Builder $query) use ($primarySegment): void {
                    $query->where('segment_key', $primarySegment->value)
                        ->where('subscribed', true)
                        ->whereNotNull('email')
                        ->where('email', '!=', '');
                })
                ->orderBy('id')
                ->limit($perType)
                ->get();

            if ($customers->isEmpty()) {
                $summary[$label] = [
                    'customers' => 0,
                    'subscriptions' => 0,
                    'segments' => self::segmentLabelsForType($type),
                ];

                continue;
            }

            $rows = collect();
            $segmentLabels = [];

            foreach ($customers as $customer) {
                $billingRow = NewsletterSubscription::query()
                    ->where('subscribable_type', Customer::class)
                    ->where('subscribable_id', $customer->getKey())
                    ->where('segment_key', $primarySegment->value)
                    ->where('subscribed', true)
                    ->first();

                if ($billingRow !== null) {
                    $rows->push($billingRow);
                    $segmentLabels[$primarySegment->getLabel()] = true;
                }

                if ($shippingSegment !== null && NewsletterSubscriptionWriter::shouldSyncShippingNewsletterSegment($customer)) {
                    $shippingRow = NewsletterSubscription::query()
                        ->where('subscribable_type', Customer::class)
                        ->where('subscribable_id', $customer->getKey())
                        ->where('segment_key', $shippingSegment->value)
                        ->where('subscribed', true)
                        ->first();

                    if ($shippingRow !== null) {
                        $rows->push($shippingRow);
                        $segmentLabels[$shippingSegment->getLabel()] = true;
                    }
                }
            }

            $summary[$label] = [
                'customers' => $customers->count(),
                'subscriptions' => $rows->count(),
                'segments' => $segmentLabels !== [] ? implode(', ', array_keys($segmentLabels)) : self::segmentLabelsForType($type),
            ];

            foreach ($rows->pluck('id') as $id) {
                $subscriptionIds[] = (int) $id;
            }

            $pendingDeactivations = NewsletterSubscription::query()
                ->where('subscribable_type', Customer::class)
                ->whereIn('subscribable_id', $customers->pluck('id'))
                ->where('needs_sync', true)
                ->where('subscribed', false)
                ->pluck('id');

            foreach ($pendingDeactivations as $id) {
                $subscriptionIds[] = (int) $id;
            }
        }

        return [
            'subscription_ids' => array_values(array_unique($subscriptionIds)),
            'summary' => $summary,
            'pending_deactivations' => self::countPendingDeactivationsInIds($subscriptionIds),
        ];
    }

    /**
     * @param  list<int>  $subscriptionIds
     */
    private static function countPendingDeactivationsInIds(array $subscriptionIds): int
    {
        if ($subscriptionIds === []) {
            return 0;
        }

        return NewsletterSubscription::query()
            ->whereIn('id', $subscriptionIds)
            ->where('needs_sync', true)
            ->where('subscribed', false)
            ->count();
    }

    private static function segmentLabelsForType(CustomerType $type): string
    {
        $labels = [];

        if ($billing = $type->billingNewsletterSegment()) {
            $labels[] = $billing->getLabel();
        }

        if ($shipping = $type->shippingNewsletterSegment()) {
            $labels[] = $shipping->getLabel();
        }

        return implode(', ', $labels);
    }
}
