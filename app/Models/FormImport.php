<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FormImport extends Model
{
    protected $fillable = [
        'form_import_connection_id',
        'source_form_id',
        'source_form_title',
        'is_active',
        'uid_source_field_id',
        'uid_fallback_prefix',
        'last_imported_at',
        'last_imported_count',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_imported_at' => 'datetime',
            'last_imported_count' => 'integer',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(FormImportConnection::class, 'form_import_connection_id');
    }

    public function fieldMappings(): HasMany
    {
        return $this->hasMany(FormImportFieldMapping::class)->orderBy('sort_order');
    }

    public function entryLogs(): HasMany
    {
        return $this->hasMany(FormImportEntryLog::class);
    }

    public function state(): HasOne
    {
        return $this->hasOne(FormImportState::class);
    }
}
