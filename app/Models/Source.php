<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $notes
 * @property int|null $customer_id
 * @property int $import_template_id
 */
class Source extends Model
{
    protected $fillable = [
        'name',
        'email',
        'notes',
        'customer_id',
        'import_template_id',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function importTemplate(): BelongsTo
    {
        return $this->belongsTo(ImportTemplate::class);
    }

    public function importRows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}
