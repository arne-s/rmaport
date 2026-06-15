<?php

namespace App\Models;

use App\Enums\ImportTemplateType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $filename
 * @property string $class
 * @property ImportTemplateType $type
 * @property string|null $description
 * @property int|null $source_id
 */
class ImportTemplate extends Model
{
    protected $fillable = [
        'name',
        'filename',
        'class',
        'type',
        'description',
        'source_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => ImportTemplateType::class,
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    public function isUniversal(): bool
    {
        return Str::contains($this->class, 'UniversalImportParser');
    }
}
