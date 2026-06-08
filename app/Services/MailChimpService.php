<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Enums\NewsletterSubscriptionSegment;
use App\Models\Customer;
use App\Models\NewsletterSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MailChimpService
{
    protected string $baseUrl;

    protected ?string $apiKey;

    protected ?string $dataCenter;

    protected ?string $audienceId;

    public function __construct()
    {
        $this->apiKey = $this->stringOrNull(config('mailchimp.key') ?? config('services.mailchimp.key'));
        $this->dataCenter = $this->stringOrNull(config('mailchimp.data_center') ?? config('services.mailchimp.data_center'));
        $this->audienceId = $this->stringOrNull(config('mailchimp.audience_id') ?? config('services.mailchimp.audience_id'));
        $this->baseUrl = $this->dataCenter !== null
            ? "https://{$this->dataCenter}.api.mailchimp.com/3.0/"
            : '';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null
            && $this->apiKey !== ''
            && $this->dataCenter !== null
            && $this->dataCenter !== ''
            && $this->audienceId !== null
            && $this->audienceId !== '';
    }

    /**
     * List all segments for an audience (paginated).
     *
     * @return list<array{id: int|string, name: string, type: string, member_count: int}>
     *
     * @throws \RuntimeException When API credentials or list id are missing, or the request fails.
     */
    public function listAllAudienceSegments(?string $audienceId = null): array
    {
        $listId = $audienceId ?? $this->audienceId;
        if ($this->apiKey === null || $this->apiKey === '' || $this->dataCenter === null || $this->dataCenter === '' || $listId === null || $listId === '') {
            throw new \RuntimeException('Mailchimp API key, data center, and audience (list) id are required.');
        }

        $url = $this->baseUrl.'lists/'.$listId.'/segments';
        $all = [];
        $offset = 0;
        $pageSize = 100;

        while (true) {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])->get($url, [
                'count' => $pageSize,
                'offset' => $offset,
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Mailchimp segments request failed ('.$response->status().'): '.$response->body());
            }

            /** @var array{segments?: list<array<string, mixed>>, total_items?: int} $body */
            $body = $response->json();
            $segments = $body['segments'] ?? [];
            if ($segments === []) {
                break;
            }

            foreach ($segments as $segment) {
                $id = $segment['id'] ?? null;
                $all[] = [
                    'id' => is_int($id) || is_string($id) ? $id : 0,
                    'name' => is_string($segment['name'] ?? null) ? $segment['name'] : '',
                    'type' => is_string($segment['type'] ?? null) ? $segment['type'] : '',
                    'member_count' => is_int($segment['member_count'] ?? null) ? $segment['member_count'] : (int) ($segment['member_count'] ?? 0),
                ];
            }

            $offset += count($segments);
            $total = (int) ($body['total_items'] ?? 0);
            if ($offset >= $total || count($segments) < $pageSize) {
                break;
            }
        }

        return $all;
    }

    public function pushSubscription(NewsletterSubscription $subscription): bool
    {
        if (! $this->isConfigured()) {
            Log::channel('mailchimpLog')->warning('Mailchimp not configured; skip push', [
                'subscription_id' => $subscription->getKey(),
            ]);

            return false;
        }

        $segmentKey = $subscription->segment_key;
        $tag = config("mailchimp.tags.{$segmentKey}");
        if (! is_string($tag) || $tag === '') {
            Log::channel('mailchimpLog')->error('Missing Mailchimp tag for segment', ['segment' => $segmentKey]);
            $subscription->forceFill([
                'last_error' => 'Missing Mailchimp tag for segment: '.$segmentKey,
            ])->saveQuietly();

            return false;
        }

        $email = NewsletterSubscriptionWriter::normalizeEmail($subscription->email);
        if ($email === null) {
            return false;
        }

        $hash = md5($email);

        if (! $subscription->subscribed) {
            return $this->pushSubscriptionTagOnlyInactive($subscription, $hash, $tag);
        }

        $subscriber = [
            'email_address' => $email,
            'status' => 'subscribed',
            'merge_fields' => [
                'SEGMENT' => $tag,
            ],
        ];

        $this->applyMergeNames($subscriber, $subscription);

        $url = $this->baseUrl.'lists/'.$this->audienceId.'/members/'.$hash;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])->put($url, $subscriber);

            if ($response->successful()) {
                $tagResult = $this->postMemberTag($hash, $tag, true);

                if ($tagResult['ok']) {
                    $subscription->forceFill([
                        'needs_sync' => false,
                        'last_synced_at' => now(),
                        'last_error' => null,
                    ])->saveQuietly();

                    return true;
                }

                $subscription->forceFill([
                    'needs_sync' => true,
                    'last_error' => 'Mailchimp tag activation failed: '.$tagResult['detail'],
                ])->saveQuietly();
                Log::channel('mailchimpLog')->warning('Mailchimp tag activation failed after member PUT', [
                    'subscription_id' => $subscription->getKey(),
                    'tag' => $tag,
                    'detail' => $tagResult['detail'],
                ]);

                return false;
            }

            $subscription->forceFill([
                'last_error' => $response->body(),
            ])->saveQuietly();
            Log::channel('mailchimpLog')->error('Mailchimp push failed', [
                'subscription_id' => $subscription->getKey(),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            $subscription->forceFill([
                'last_error' => $e->getMessage(),
            ])->saveQuietly();
            Log::channel('mailchimpLog')->error('Mailchimp push exception', [
                'subscription_id' => $subscription->getKey(),
                'exception' => (string) $e,
            ]);

            return false;
        }
    }

    /**
     * Turn off only this segment's audience tag. Avoid PUT with status "unsubscribed", which would remove the contact from the entire audience while other segments may still apply.
     */
    private function pushSubscriptionTagOnlyInactive(NewsletterSubscription $subscription, string $memberHash, string $tag): bool
    {
        try {
            $tagResult = $this->postMemberTag($memberHash, $tag, false);
            if (! $tagResult['ok']) {
                $subscription->forceFill([
                    'last_error' => 'Mailchimp tag deactivation failed: '.$tagResult['detail'],
                ])->saveQuietly();
                Log::channel('mailchimpLog')->warning('Mailchimp tag deactivation failed', [
                    'subscription_id' => $subscription->getKey(),
                    'tag' => $tag,
                    'detail' => $tagResult['detail'],
                ]);

                return false;
            }

            $subscription->forceFill([
                'needs_sync' => false,
                'last_synced_at' => now(),
                'last_error' => null,
            ])->saveQuietly();

            return true;

        } catch (Throwable $e) {
            $subscription->forceFill([
                'last_error' => $e->getMessage(),
            ])->saveQuietly();
            Log::channel('mailchimpLog')->error('Mailchimp tag-only push exception', [
                'subscription_id' => $subscription->getKey(),
                'exception' => (string) $e,
            ]);

            return false;
        }
    }

    /**
     * @param  list<int>|null  $onlySubscriptionIds
     * @param  (callable(): void)|null  $onProgress
     * @return array{processed: int, succeeded: int, failed: int}
     */
    public function pushPendingNewsletterSubscriptions(
        ?int $limit = null,
        ?array $onlySubscriptionIds = null,
        ?callable $onProgress = null,
    ): array {
        $query = NewsletterSubscription::query()->orderBy('id');

        if ($onlySubscriptionIds !== null) {
            $query = $this->applyPushCandidateFilters($query, $onlySubscriptionIds);
        } else {
            $query->where('needs_sync', true);
        }

        if ($limit !== null && $onlySubscriptionIds === null) {
            $query->limit($limit);
        }

        return $this->processPushQuery(
            $query,
            $onProgress,
            chunk: $limit === null && $onlySubscriptionIds === null,
        );
    }

    public function countPushCandidates(?int $limit = null, ?array $onlySubscriptionIds = null): int
    {
        $query = NewsletterSubscription::query();

        if ($onlySubscriptionIds !== null) {
            return $this->applyPushCandidateFilters($query, $onlySubscriptionIds)->count();
        }

        $query->where('needs_sync', true);

        if ($limit !== null) {
            return min($limit, $query->count());
        }

        return $query->count();
    }

    /**
     * @param  list<int>  $onlySubscriptionIds
     */
    private function applyPushCandidateFilters(\Illuminate\Database\Eloquent\Builder $query, array $onlySubscriptionIds): \Illuminate\Database\Eloquent\Builder
    {
        $customerIds = NewsletterSubscription::query()
            ->whereIn('id', $onlySubscriptionIds)
            ->pluck('subscribable_id')
            ->unique()
            ->filter()
            ->values()
            ->all();

        return $query->where(function (Builder $inner) use ($onlySubscriptionIds, $customerIds): void {
            $inner->whereIn('id', $onlySubscriptionIds);

            if ($customerIds !== []) {
                $inner->orWhere(function (Builder $pending) use ($customerIds): void {
                    $pending->where('needs_sync', true)
                        ->where('subscribed', false)
                        ->where('subscribable_type', Customer::class)
                        ->whereIn('subscribable_id', $customerIds);
                });
            }
        });
    }

    /**
     * @param  (callable(): void)|null  $onProgress
     * @return array{processed: int, succeeded: int, failed: int}
     */
    private function processPushQuery(\Illuminate\Database\Eloquent\Builder $query, ?callable $onProgress, bool $chunk = true): array
    {
        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        $handle = function (NewsletterSubscription $subscription) use (&$processed, &$succeeded, &$failed, $onProgress): void {
            ++$processed;

            if ($this->pushSubscription($subscription)) {
                ++$succeeded;
            } else {
                ++$failed;
            }

            if ($onProgress !== null) {
                $onProgress();
            }
        };

        if ($chunk) {
            $query->chunkById(50, function ($chunk) use ($handle): void {
                foreach ($chunk as $subscription) {
                    $handle($subscription);
                }
            });
        } else {
            foreach ($query->get() as $subscription) {
                $handle($subscription);
            }
        }

        return [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
        ];
    }

    /**
     * Pull Mailchimp unsubscribe events into the ERP (newsletter flags only; no name/email).
     *
     * @param  list<int>|null  $onlySubscriptionIds
     * @param  (callable(): void)|null  $onProgress
     * @return array{processed: int, unsubscribed: int}
     */
    public function pullUnsubscribesFromMailchimp(
        ?int $limit = null,
        ?array $onlySubscriptionIds = null,
        ?callable $onProgress = null,
    ): array {
        if (! $this->isConfigured()) {
            return ['processed' => 0, 'unsubscribed' => 0];
        }

        $processed = 0;
        $unsubscribed = 0;
        /** @var array<string, string|null> $memberStatusCache */
        $memberStatusCache = [];
        /** @var array<string, array<string, bool>|null> $memberTagsCache */
        $memberTagsCache = [];

        $query = NewsletterSubscription::query()
            ->where('subscribed', true)
            ->orderBy('id');

        if ($onlySubscriptionIds !== null) {
            $query->whereIn('id', $onlySubscriptionIds);
        } elseif ($limit !== null) {
            $query->limit($limit);
        }

        $process = function (NewsletterSubscription $subscription) use (
            &$processed,
            &$unsubscribed,
            &$memberStatusCache,
            &$memberTagsCache,
            $onProgress,
        ): void {
            ++$processed;

            if ($this->shouldApplyMailchimpUnsubscribe($subscription, $memberStatusCache, $memberTagsCache)) {
                $this->applyPulledUnsubscribe($subscription);
                ++$unsubscribed;
            }

            if ($onProgress !== null) {
                $onProgress();
            }
        };

        if ($onlySubscriptionIds !== null || $limit !== null) {
            foreach ($query->get() as $subscription) {
                $process($subscription);
            }
        } else {
            $query->chunkById(50, function ($chunk) use ($process): void {
                foreach ($chunk as $subscription) {
                    $process($subscription);
                }
            });
        }

        if ($unsubscribed > 0) {
            Log::channel('mailchimpLog')->info('Mailchimp unsubscribe pull applied', [
                'rows_updated' => $unsubscribed,
            ]);
        }

        return [
            'processed' => $processed,
            'unsubscribed' => $unsubscribed,
        ];
    }

    public function countPullCandidates(?int $limit = null, ?array $onlySubscriptionIds = null): int
    {
        $query = NewsletterSubscription::query()->where('subscribed', true);

        if ($onlySubscriptionIds !== null) {
            return $query->whereIn('id', $onlySubscriptionIds)->count();
        }

        if ($limit !== null) {
            return min($limit, $query->count());
        }

        return $query->count();
    }

    /**
     * @param  array<string, string|null>  $memberStatusCache
     * @param  array<string, array<string, bool>|null>  $memberTagsCache
     */
    private function shouldApplyMailchimpUnsubscribe(
        NewsletterSubscription $subscription,
        array &$memberStatusCache,
        array &$memberTagsCache,
    ): bool {
        $email = NewsletterSubscriptionWriter::normalizeEmail($subscription->email);
        if ($email === null) {
            return false;
        }

        $tag = config("mailchimp.tags.{$subscription->segment_key}");
        if (! is_string($tag) || $tag === '') {
            return false;
        }

        $hash = md5($email);
        $memberStatus = $this->fetchMemberStatus($hash, $memberStatusCache);

        if ($memberStatus === null) {
            return false;
        }

        if ($memberStatus !== 'subscribed') {
            return true;
        }

        if ($subscription->last_synced_at !== null && $subscription->last_synced_at->greaterThan(now()->subMinutes(15))) {
            return false;
        }

        $tagStates = $this->fetchMemberTagStates($hash, $memberTagsCache);
        if ($tagStates === null) {
            return false;
        }

        if (! array_key_exists($tag, $tagStates)) {
            return false;
        }

        return $tagStates[$tag] !== true;
    }

    /**
     * @param  array<string, string|null>  $cache
     */
    private function fetchMemberStatus(string $memberHash, array &$cache): ?string
    {
        if (array_key_exists($memberHash, $cache)) {
            return $cache[$memberHash];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])->get($this->baseUrl.'lists/'.$this->audienceId.'/members/'.$memberHash);

            if ($response->status() === 404) {
                $cache[$memberHash] = null;

                return null;
            }

            if (! $response->successful()) {
                Log::channel('mailchimpLog')->warning('Mailchimp member status request failed', [
                    'hash' => $memberHash,
                    'status' => $response->status(),
                    'body' => $this->summarizeMailchimpResponseBody($response->body()),
                ]);
                $cache[$memberHash] = null;

                return null;
            }

            $status = $response->json('status');
            $cache[$memberHash] = is_string($status) ? $status : null;

            return $cache[$memberHash];
        } catch (Throwable $e) {
            Log::channel('mailchimpLog')->error('Mailchimp member status exception', [
                'hash' => $memberHash,
                'exception' => (string) $e,
            ]);
            $cache[$memberHash] = null;

            return null;
        }
    }

    /**
     * @param  array<string, array<string, bool>|null>  $cache
     * @return array<string, bool>|null
     */
    private function fetchMemberTagStates(string $memberHash, array &$cache): ?array
    {
        if (array_key_exists($memberHash, $cache)) {
            return $cache[$memberHash];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])->get($this->baseUrl.'lists/'.$this->audienceId.'/members/'.$memberHash.'/tags');

            if ($response->status() === 404) {
                $cache[$memberHash] = [];

                return $cache[$memberHash];
            }

            if (! $response->successful()) {
                Log::channel('mailchimpLog')->warning('Mailchimp member tags request failed', [
                    'hash' => $memberHash,
                    'status' => $response->status(),
                    'body' => $this->summarizeMailchimpResponseBody($response->body()),
                ]);
                $cache[$memberHash] = null;

                return null;
            }

            /** @var array{tags?: list<array<string, mixed>>} $body */
            $body = $response->json();
            $states = [];

            foreach ($body['tags'] ?? [] as $tag) {
                $name = $tag['name'] ?? null;
                if (! is_string($name) || $name === '') {
                    continue;
                }

                $states[$name] = ($tag['status'] ?? '') === 'active';
            }

            $cache[$memberHash] = $states;

            return $states;
        } catch (Throwable $e) {
            Log::channel('mailchimpLog')->error('Mailchimp member tags exception', [
                'hash' => $memberHash,
                'exception' => (string) $e,
            ]);
            $cache[$memberHash] = null;

            return null;
        }
    }

    private function applyPulledUnsubscribe(NewsletterSubscription $row): void
    {
        $row->forceFill([
            'subscribed' => false,
            'needs_sync' => false,
            'last_synced_at' => now(),
            'last_error' => null,
        ])->saveQuietly();

        $this->mirrorSubscriptionToErp($row, subscribed: false);
    }

    /**
     * Subscribe a user from a form submission (website).
     *
     * @param  array{email: string, first_name: string, middle_name?: string|null, last_name: string}  $submission
     */
    public function subscribeFromForm(array $submission): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $tag = config('mailchimp.tags.customer_b2c', 'Particulier');

        $subscriber = [
            'email_address' => $submission['email'],
            'status' => 'subscribed',
            'merge_fields' => [
                'SEGMENT' => $tag,
                'FNAME' => $submission['first_name'],
            ],
        ];

        $subscriber['merge_fields']['LNAME'] = implode(' ', array_filter([
            $submission['middle_name'] ?? null,
            $submission['last_name'],
        ]));

        $url = $this->baseUrl.'lists/'.$this->audienceId.'/members';
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])->post($url, $subscriber);

            if ($response->status() === 200 || $response->status() === 201) {
                $hash = md5(strtolower($submission['email']));
                $tagResult = $this->postMemberTag($hash, (string) $tag, true);
                if (! $tagResult['ok']) {
                    Log::channel('mailchimpLog')->warning('Mailchimp form tag failed', [
                        'tag' => $tag,
                        'detail' => $tagResult['detail'],
                        'email' => $submission['email'],
                    ]);
                }
            }
            Log::channel('mailchimpLog')->info('Inschrijf formulier - Mailchimp response:', [$response?->status()]);
        } catch (Throwable $e) {
            Log::channel('mailchimpLog')->error('Mailchimp inschrijf formulier error:', ['exception' => (string) $e, 'submission' => $submission]);
        }
    }

    /**
     * @deprecated Use {@see pullUnsubscribesFromMailchimp()} instead.
     */
    public function pullAllConfiguredSegments(): void
    {
        $this->pullUnsubscribesFromMailchimp();
    }

    /**
     * @deprecated Use {@see pullUnsubscribesFromMailchimp()} instead.
     */
    public function syncFromMailChimp(): void
    {
        $this->pullUnsubscribesFromMailchimp();
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function postMemberTag(string $memberHash, string $tagName, bool $active): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'detail' => 'Mailchimp not configured'];
        }

        $tagUrl = $this->baseUrl.'lists/'.$this->audienceId.'/members/'.$memberHash.'/tags';
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
        ])->post($tagUrl, [
            'tags' => [
                ['name' => $tagName, 'status' => $active ? 'active' : 'inactive'],
            ],
        ]);

        if ($response->successful()) {
            return ['ok' => true, 'detail' => ''];
        }

        if (! $active && $response->status() === 404) {
            Log::channel('mailchimpLog')->info('Mailchimp tag deactivation skipped: member not in audience (404)', [
                'tag' => $tagName,
            ]);

            return ['ok' => true, 'detail' => ''];
        }

        return [
            'ok' => false,
            'detail' => 'HTTP '.$response->status().' '.$this->summarizeMailchimpResponseBody($response->body()),
        ];
    }

    private function summarizeMailchimpResponseBody(?string $body): string
    {
        $trimmed = trim((string) $body);
        if ($trimmed === '') {
            return '(empty response body)';
        }

        return mb_strlen($trimmed) > 800 ? mb_substr($trimmed, 0, 800).'…' : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $subscriber
     */
    private function applyMergeNames(array &$subscriber, NewsletterSubscription $subscription): void
    {
        $model = $subscription->subscribable;
        if ($model instanceof Customer) {
            if (filled($model->getFirstName())) {
                $subscriber['merge_fields']['FNAME'] = $model->getFirstName();
            }
            $last = trim(implode(' ', array_filter([$model->getMiddleName(), $model->getLastName()])));
            if ($last !== '') {
                $subscriber['merge_fields']['LNAME'] = $last;
            }
        }
    }

    private function mirrorSubscriptionToErp(NewsletterSubscription $subscription, bool $subscribed): void
    {
        if ($subscribed) {
            return;
        }

        $model = $subscription->subscribable;
        if (! $model instanceof Customer) {
            return;
        }

        $segment = NewsletterSubscriptionSegment::tryFrom($subscription->segment_key);
        if ($segment === null) {
            return;
        }

        if ($segment === NewsletterSubscriptionSegment::CustomerB2c) {
            $this->mirrorB2cNewsletterToErp($model);

            return;
        }

        if ($segment->isBilling()) {
            $this->mirrorBillingNewsletterToErp($model);

            return;
        }

        if ($segment->isShipping()) {
            $this->mirrorShippingNewsletterToErp($model);
        }
    }

    private function mirrorB2cNewsletterToErp(Customer $customer): void
    {
        $type = $customer->getType();
        if ($type !== null && $type !== CustomerType::B2C) {
            return;
        }

        if ((bool) $customer->newsletter_subscribed === false) {
            return;
        }

        $customer->forceFill(['newsletter_subscribed' => false])->saveQuietly();
    }

    private function mirrorBillingNewsletterToErp(Customer $customer): void
    {
        if ($customer->getType()?->usesNewsletterDealerSegments() !== true) {
            return;
        }

        $customer->loadMissing('billingAddress');
        $billing = $customer->billingAddress;
        if ($billing === null || (bool) $billing->newsletter_subscribed === false) {
            return;
        }

        $billing->forceFill(['newsletter_subscribed' => false])->saveQuietly();
    }

    private function mirrorShippingNewsletterToErp(Customer $customer): void
    {
        if ($customer->getType()?->usesNewsletterDealerSegments() !== true) {
            return;
        }

        if (($customer->delivery_address_type ?? 'contact') !== 'custom') {
            return;
        }

        $customer->loadMissing('shippingAddress');
        $shipping = $customer->shippingAddress;
        if ($shipping === null || (bool) $shipping->newsletter_subscribed === false) {
            return;
        }

        $shipping->forceFill(['newsletter_subscribed' => false])->saveQuietly();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return $value;
    }
}
