<?php

namespace App\Models;

use App\Enums\NoteStatus;
use App\Enums\NoteType;
use App\Models\Order\Main;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * App\Models\Note
 *
 * @property int $id
 * @property NoteType $type
 * @property string|null $content
 * @property array|null $additional
 * @property int $user_id
 * @property int|null $customer_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Customer|null $customer
 * @property-read MediaCollection<int, Media> $media
 * @property-read int|null $media_count
 */
class Note extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'notes';

    protected $fillable = [
        'type',
        'status',
        'content',
        'additional',
        'user_id',
        'customer_id',
    ];

    protected $casts = [
        'type' => NoteType::class,
        'status' => NoteStatus::class,
        'additional' => 'array',
    ];

    /**
     * Get the user (author) that created the note.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the customer associated with the note.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the display name of the note's subject (customer).
     * Used as "Author" in tables.
     */
    public function getAuthorAttribute(): ?string
    {
        if ($this->customer_id) {
            return $this->customer?->getName();
        }

        return null;
    }

    /**
     * Get all of the customers that are assigned this note.
     */
    public function customers(): MorphToMany
    {
        return $this->morphedByMany(Customer::class, 'model', 'model_has_notes');
    }

    /**
     * Get all of the orders that are assigned this note.
     */
    public function orders(): MorphToMany
    {
        return $this->morphedByMany(Main::class, 'model', 'model_has_notes');
    }

    /**
     * Get all of the users that are assigned this note.
     */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(User::class, 'model', 'model_has_notes');
    }

    public function products(): MorphToMany
    {
        return $this->morphedByMany(Product::class, 'model', 'model_has_notes');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(NoteComments::class, 'note_id');
    }

    /**
     * Register media collections for attachments.
     * - attachments: definitive list shown in the custom attachments list component.
     * - attachments_upload: temporary collection for the Spatie upload field; moved to attachments on save.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->registerMediaConversions(function () {
                $this->addMediaConversion('thumbnail')
                    ->fit(Fit::Crop, 96, 96)
                    ->queued();
                $this->addMediaConversion('list_thumb')
                    ->fit(Fit::Crop, 32, 32)
                    ->queued();
            });

        $this->addMediaCollection('attachments_upload')
            ->registerMediaConversions(function () {
                $this->addMediaConversion('thumbnail')
                    ->fit(Fit::Crop, 96, 96)
                    ->queued();
                $this->addMediaConversion('list_thumb')
                    ->fit(Fit::Crop, 32, 32)
                    ->queued();
            });
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return NoteType
     */
    public function getType(): NoteType
    {
        return $this->type;
    }

    /**
     * @param NoteType $type
     * @return Note
     */
    public function setType(NoteType $type): Note
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * @param string|null $content
     * @return Note
     */
    public function setContent(?string $content): Note
    {
        $this->content = $content;
        return $this;
    }

    /**
     * @return array|null
     */
    public function getAdditional(): ?array
    {
        return $this->additional;
    }

    /**
     * @param array|null $additional
     * @return Note
     */
    public function setAdditional(?array $additional): Note
    {
        $this->additional = $additional;
        return $this;
    }

    /**
     * @return int
     */
    public function getUserId(): int
    {
        return $this->user_id;
    }

    /**
     * @param int $userId
     * @return Note
     */
    public function setUserId(int $userId): Note
    {
        $this->user_id = $userId;
        return $this;
    }

    /**
     * @return User
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return int|null
     */
    public function getCustomerId(): ?int
    {
        return $this->customer_id;
    }

    /**
     * @param int|null $customerId
     * @return Note
     */
    public function setCustomerId(?int $customerId): Note
    {
        $this->customer_id = $customerId;
        return $this;
    }

    /**
     * @return Customer|null
     */
    public function getCustomer(): ?Customer
    {
        return $this->customer;
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
