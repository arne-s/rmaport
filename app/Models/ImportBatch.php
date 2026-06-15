<?php

namespace App\Models;

use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int|null $import_template_id
 * @property string|null $track_trace_nr
 * @property string|null $reference
 * @property Carbon|null $shipment_date
 */
class ImportBatch extends Import
{
    protected $table = 'imports';

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
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

    public function uploader(): BelongsTo
    {
        return $this->user();
    }
}
