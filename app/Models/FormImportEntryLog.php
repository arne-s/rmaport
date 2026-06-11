<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormImportEntryLog extends Model
{
    protected $fillable = [
        'form_import_id',
        'source_form_id',
        'source_entry_id',
        'rma_id',
        'imported_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function formImport(): BelongsTo
    {
        return $this->belongsTo(FormImport::class);
    }

    public function rma(): BelongsTo
    {
        return $this->belongsTo(Rma::class);
    }
}
