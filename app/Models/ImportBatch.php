<?php

namespace App\Models;

use App\Support\ImportBatchNumberSequence;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int|null $import_template_id
 * @property string $uid
 * @property string|null $track_trace_nr
 * @property string|null $reference
 * @property Carbon|null $import_date
 * @property Carbon|null $shipment_date
 * @property string|null $shipment_reference
 */
class ImportBatch extends Import implements HasMedia
{
    use InteractsWithMedia;
    protected $table = 'imports';

    protected static function booted(): void
    {
        static::creating(function (ImportBatch $batch): void {
            if (blank($batch->uid)) {
                $batch->uid = static::generateNextUid();
            }
        });
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'import_date' => 'date',
            'shipment_date' => 'date',
        ]);
    }

    protected function filename(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->attributes['file_name'] ?? null,
            set: fn (?string $value): array => ['file_name' => $value],
        );
    }

    public function importTemplate(): BelongsTo
    {
        return $this->belongsTo(ImportTemplate::class);
    }

    public function importRows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'import_id');
    }

    public function export(): HasOne
    {
        return $this->hasOne(ImportExport::class, 'import_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('documents');
    }

    public function uploader(): BelongsTo
    {
        return $this->user();
    }

    public static function generateNextUid(): string
    {
        return DB::transaction(fn (): string => ImportBatchNumberSequence::next());
    }
}
