<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\NewsletterSubscriptionSegment;
use App\Models\Customer;
use App\Models\NewsletterSubscription;

class NewsletterSubscriptionWriter
{
    public static function isCustomerActiveForNewsletter(Customer $customer): bool
    {
        return $customer->getStatus() === CustomerStatus::Active;
    }

    /**
     * Uncheck ERP "inschrijven" flags when a customer becomes inactive so the UI matches Mailchimp intent
     * and a later re-activation does not silently resubscribe.
     */
    public static function clearStoredNewsletterPreferences(Customer $customer): void
    {
        $customer->newsletter_subscribed = false;

        $customer->loadMissing(['billingAddress', 'shippingAddress', 'address']);

        foreach (array_filter([$customer->billingAddress, $customer->shippingAddress, $customer->address]) as $address) {
            if (! $address->newsletter_subscribed) {
                continue;
            }

            $address->forceFill(['newsletter_subscribed' => false])->saveQuietly();
        }
    }

    public static function syncFromCustomer(Customer $customer, ?string $consentSource = null): void
    {
        $customer->loadMissing(['billingAddress', 'shippingAddress']);

        if (self::isNewsletterB2cCustomer($customer)) {
            self::syncB2cCustomer($customer, $consentSource);

            return;
        }

        if ($customer->getType()?->usesNewsletterDealerSegments() === true) {
            self::syncBusinessCustomer($customer, $consentSource);

            return;
        }

        self::deactivateAllNewsletterSegmentsForCustomer($customer);
    }

    /**
     * AV and other non-visible business types: geen Mailchimp B2B-/B2C-rijen.
     */
    private static function deactivateAllNewsletterSegmentsForCustomer(Customer $customer): void
    {
        foreach (NewsletterSubscriptionSegment::cases() as $segment) {
            self::deactivateCustomerSegment($customer, $segment);
        }
    }

    /**
     * B2C newsletter uses {@see Customer::$email}; treat unknown/null customer type the same as B2C for backwards compatibility.
     */
    private static function isNewsletterB2cCustomer(Customer $customer): bool
    {
        $type = $customer->getType();

        return $type === null || $type === CustomerType::B2C;
    }

    private static function syncB2cCustomer(Customer $customer, ?string $consentSource): void
    {
        self::deactivateAllExcept($customer, NewsletterSubscriptionSegment::CustomerB2c);

        $email = self::normalizeEmail($customer->getEmail());
        if ($email === null) {
            self::deactivateCustomerSegment($customer, NewsletterSubscriptionSegment::CustomerB2c);

            return;
        }

        $subscribed = self::effectiveNewsletterSubscribed($customer, (bool) $customer->newsletter_subscribed);

        self::upsertRow(
            subscribable: $customer,
            segment: NewsletterSubscriptionSegment::CustomerB2c,
            email: $email,
            subscribed: $subscribed,
            consentSource: $consentSource ?? ($subscribed ? 'erp_customer' : null),
        );
    }

    private static function syncBusinessCustomer(Customer $customer, ?string $consentSource): void
    {
        $type = $customer->getType();
        if ($type === null) {
            self::deactivateAllNewsletterSegmentsForCustomer($customer);

            return;
        }

        $billingSegment = $type->billingNewsletterSegment();
        $shippingSegment = $type->shippingNewsletterSegment();

        if ($billingSegment === null) {
            self::deactivateAllNewsletterSegmentsForCustomer($customer);

            return;
        }

        $keepSegments = [$billingSegment];

        if ($shippingSegment !== null && self::shouldSyncShippingNewsletterSegment($customer)) {
            $keepSegments[] = $shippingSegment;
        }

        self::deactivateAllExcept($customer, ...$keepSegments);

        $billing = $customer->billingAddress;
        $shipping = $customer->shippingAddress;

        $billingEmail = self::normalizeEmail($billing?->getEmail() ?? $customer->getEmail());
        $billingSubscribed = self::effectiveNewsletterSubscribed(
            $customer,
            (bool) ($billing?->newsletter_subscribed ?? $customer->newsletter_subscribed ?? false),
        );

        if ($billingEmail === null) {
            self::deactivateCustomerSegment($customer, $billingSegment);
        } else {
            self::upsertRow(
                subscribable: $customer,
                segment: $billingSegment,
                email: $billingEmail,
                subscribed: $billingSubscribed,
                consentSource: $consentSource ?? ($billingSubscribed ? 'erp_customer' : null),
            );
        }

        if ($shippingSegment === null || ! self::shouldSyncShippingNewsletterSegment($customer)) {
            if ($shippingSegment !== null) {
                self::deactivateCustomerSegment($customer, $shippingSegment);
            }

            return;
        }

        $shippingEmail = self::normalizeEmail($shipping?->getEmail());
        $shippingSubscribed = self::effectiveNewsletterSubscribed($customer, (bool) ($shipping?->newsletter_subscribed ?? false));

        self::upsertRow(
            subscribable: $customer,
            segment: $shippingSegment,
            email: $shippingEmail,
            subscribed: $shippingSubscribed,
            consentSource: $consentSource ?? ($shippingSubscribed ? 'erp_customer' : null),
        );
    }

    /**
     * @param  NewsletterSubscriptionSegment  ...$keep
     */
    private static function deactivateAllExcept(Customer $customer, NewsletterSubscriptionSegment ...$keep): void
    {
        $keepValues = array_map(static fn (NewsletterSubscriptionSegment $segment): string => $segment->value, $keep);

        foreach (NewsletterSubscriptionSegment::cases() as $segment) {
            if (! in_array($segment->value, $keepValues, true)) {
                self::deactivateCustomerSegment($customer, $segment);
            }
        }
    }

    /**
     * Mark a segment row unsubscribed and queue a Mailchimp sync when it was subscribed.
     */
    private static function deactivateCustomerSegment(Customer $customer, NewsletterSubscriptionSegment $segment): void
    {
        $row = NewsletterSubscription::query()->where([
            'subscribable_type' => $customer->getMorphClass(),
            'subscribable_id' => $customer->getKey(),
            'segment_key' => $segment->value,
        ])->first();

        if ($row === null || ! $row->subscribed) {
            return;
        }

        $row->forceFill([
            'subscribed' => false,
            'needs_sync' => true,
            'consented_at' => null,
            'consent_source' => null,
            'last_error' => null,
        ])->saveQuietly();
    }

    /**
     * Rebuild newsletter_subscriptions from the ERP (no Mailchimp calls).
     */
    public static function populateAllFromErp(): void
    {
        Customer::query()->orderBy('id')->chunkById(200, function ($customers): void {
            foreach ($customers as $customer) {
                self::syncFromCustomer($customer, 'command_local_only');
            }
        });
    }

    public static function markNeedsSyncForEmail(string $email, NewsletterSubscriptionSegment $segment): void
    {
        $normalized = self::normalizeEmail($email);
        if ($normalized === null) {
            return;
        }

        NewsletterSubscription::query()
            ->where('segment_key', $segment->value)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($normalized)])
            ->update(['needs_sync' => true]);
    }

    private static function upsertRow(
        Customer $subscribable,
        NewsletterSubscriptionSegment $segment,
        string $email,
        bool $subscribed,
        ?string $consentSource,
    ): void {
        $existing = NewsletterSubscription::query()->where([
            'subscribable_type' => $subscribable->getMorphClass(),
            'subscribable_id' => $subscribable->getKey(),
            'segment_key' => $segment->value,
        ])->first();

        $dirty = $existing === null
            || $existing->email !== $email
            || (bool) $existing->subscribed !== $subscribed;

        $attributes = [
            'email' => $email,
            'subscribed' => $subscribed,
            'needs_sync' => $dirty ? true : (bool) $existing->needs_sync,
            'last_error' => $dirty ? null : $existing->last_error,
        ];

        if (! $subscribed) {
            $attributes['consented_at'] = null;
            $attributes['consent_source'] = null;
        } elseif ($dirty && $consentSource !== null) {
            $attributes['consented_at'] = now();
            $attributes['consent_source'] = $consentSource;
        }

        NewsletterSubscription::query()->updateOrCreate(
            [
                'subscribable_type' => $subscribable->getMorphClass(),
                'subscribable_id' => $subscribable->getKey(),
                'segment_key' => $segment->value,
            ],
            $attributes,
        );
    }

    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $trimmed = trim($email);
        if ($trimmed === '') {
            return null;
        }

        return mb_strtolower($trimmed);
    }

    private static function effectiveNewsletterSubscribed(Customer $customer, bool $storedPreference): bool
    {
        return self::isCustomerActiveForNewsletter($customer) && $storedPreference;
    }

    public static function shouldSyncShippingNewsletterSegment(Customer $customer): bool
    {
        if (($customer->delivery_address_type ?? 'contact') !== 'custom') {
            return false;
        }

        if ($customer->shipping_address_id === null) {
            return false;
        }

        $customer->loadMissing(['billingAddress', 'shippingAddress']);

        $billingEmail = self::normalizeEmail($customer->billingAddress?->getEmail() ?? $customer->getEmail());
        $shippingEmail = self::normalizeEmail($customer->shippingAddress?->getEmail());

        return $shippingEmail !== null && $shippingEmail !== $billingEmail;
    }
}
