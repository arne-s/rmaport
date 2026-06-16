<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $filename
 * @property string $class
 * @property string|null $description
 */
class ExportTemplate extends Model
{
    protected $fillable = [
        'name',
        'filename',
        'class',
        'description',
    ];

    public function importTemplates(): HasMany
    {
        return $this->hasMany(ImportTemplate::class);
    }
}
