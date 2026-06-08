<?php

namespace App\Models;

use App\Enums\NewsletterSubscriptionSegment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $subscribable_type
 * @property int $subscribable_id
 * @property string $segment_key
 * @property string $email
 * @property bool $subscribed
 * @property \Illuminate\Support\Carbon|null $consented_at
 * @property string|null $consent_source
 * @property bool $needs_sync
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class NewsletterSubscription extends Model
{
    protected $table = 'newsletter_subscriptions';

    protected $fillable = [
        'subscribable_type',
        'subscribable_id',
        'segment_key',
        'email',
        'subscribed',
        'consented_at',
        'consent_source',
        'needs_sync',
        'last_synced_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'subscribed' => 'boolean',
            'needs_sync' => 'boolean',
            'consented_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getSegment(): ?NewsletterSubscriptionSegment
    {
        return NewsletterSubscriptionSegment::tryFrom($this->segment_key);
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getSubscribableType(): string
    {
        return $this->subscribable_type;
    }

    /**
     * @return $this
     */
    public function setSubscribableType(string $subscribableType): self
    {
        $this->subscribable_type = $subscribableType;

        return $this;
    }

    /**
     * @return int
     */
    public function getSubscribableId(): int
    {
        return (int) $this->subscribable_id;
    }

    /**
     * @return $this
     */
    public function setSubscribableId(int $subscribableId): self
    {
        $this->subscribable_id = $subscribableId;

        return $this;
    }

    /**
     * @return string
     */
    public function getSegmentKey(): string
    {
        return $this->segment_key;
    }

    /**
     * @return $this
     */
    public function setSegmentKey(string $segmentKey): self
    {
        $this->segment_key = $segmentKey;

        return $this;
    }

    /**
     * @return string
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @return $this
     */
    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * @return bool
     */
    public function getSubscribed(): bool
    {
        return (bool) $this->subscribed;
    }

    /**
     * @return $this
     */
    public function setSubscribed(bool $subscribed): self
    {
        $this->subscribed = $subscribed;

        return $this;
    }

    /**
     * @return Carbon|null
     */
    public function getConsentedAt(): ?Carbon
    {
        return $this->consented_at;
    }

    /**
     * @return $this
     */
    public function setConsentedAt(?Carbon $consentedAt): self
    {
        $this->consented_at = $consentedAt;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getConsentSource(): ?string
    {
        return $this->consent_source;
    }

    /**
     * @return $this
     */
    public function setConsentSource(?string $consentSource): self
    {
        $this->consent_source = $consentSource;

        return $this;
    }

    /**
     * @return bool
     */
    public function getNeedsSync(): bool
    {
        return (bool) $this->needs_sync;
    }

    /**
     * @return $this
     */
    public function setNeedsSync(bool $needsSync): self
    {
        $this->needs_sync = $needsSync;

        return $this;
    }

    /**
     * @return Carbon|null
     */
    public function getLastSyncedAt(): ?Carbon
    {
        return $this->last_synced_at;
    }

    /**
     * @return $this
     */
    public function setLastSyncedAt(?Carbon $lastSyncedAt): self
    {
        $this->last_synced_at = $lastSyncedAt;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return $this->last_error;
    }

    /**
     * @return $this
     */
    public function setLastError(?string $lastError): self
    {
        $this->last_error = $lastError;

        return $this;
    }

    /**
     * @return Carbon|null
     */
    public function getCreatedAt(): ?Carbon
    {
        return $this->created_at;
    }

    /**
     * @return Carbon|null
     */
    public function getUpdatedAt(): ?Carbon
    {
        return $this->updated_at;
    }
}
