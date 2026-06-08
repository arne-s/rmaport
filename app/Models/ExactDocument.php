<?php

namespace App\Models;

use App\Enums\ExactDocumentMappedType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $exact_id
 * @property int|null $exact_type
 * @property string|null $exact_type_description
 * @property string|null $exact_subject
 * @property string $mapped_type
 * @property Carbon|null $document_date
 * @property Carbon|null $exact_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer $customer
 */
class ExactDocument extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'customer_id',
        'exact_id',
        'exact_type',
        'exact_type_description',
        'exact_subject',
        'mapped_type',
        'document_date',
        'exact_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'exact_synced_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('pdf')->singleFile();
    }
}
