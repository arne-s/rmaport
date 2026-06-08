<?php

namespace App\Models;

use App\Models\Order\Quote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int|null $id
 * @property string $uuid
 * @property int $quote_id
 * @property string|null $signature
 * @property string $customer_name
 * @property array<string, mixed>|null $browser
 * @property Carbon|null $approved_at
 * @property int|null $approved_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Quote|null $quote
 * @property-read User|null $approvedByUser
 */
class QuoteApproval extends Model
{
    protected $fillable = [
        'uuid',
        'quote_id',
        'signature',
        'customer_name',
        'browser',
        'approved_at',
        'approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'browser' => 'array',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->approved_at === null;
    }

    public function getId(): ?int
    {
        return $this->id !== null ? (int) $this->id : null;
    }

    public function getUuid(): string
    {
        return (string) $this->uuid;
    }

    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getQuoteId(): int
    {
        return (int) $this->quote_id;
    }

    public function setQuoteId(int $quoteId): self
    {
        $this->quote_id = $quoteId;

        return $this;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(?string $signature): self
    {
        $this->signature = $signature;

        return $this;
    }

    public function getCustomerName(): string
    {
        return (string) $this->customer_name;
    }

    public function setCustomerName(string $customerName): self
    {
        $this->customer_name = $customerName;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBrowser(): ?array
    {
        return $this->browser;
    }

    /**
     * @param  array<string, mixed>|null  $browser
     */
    public function setBrowser(?array $browser): self
    {
        $this->browser = $browser;

        return $this;
    }

    public function getApprovedAt(): ?Carbon
    {
        return $this->approved_at;
    }

    public function setApprovedAt(?Carbon $approvedAt): self
    {
        $this->approved_at = $approvedAt;

        return $this;
    }

    public function getApprovedByUserId(): ?int
    {
        return $this->approved_by_user_id !== null ? (int) $this->approved_by_user_id : null;
    }

    public function setApprovedByUserId(?int $approvedByUserId): self
    {
        $this->approved_by_user_id = $approvedByUserId;

        return $this;
    }

    public function getCreatedAt(): ?Carbon
    {
        return $this->created_at;
    }

    public function getUpdatedAt(): ?Carbon
    {
        return $this->updated_at;
    }
}
