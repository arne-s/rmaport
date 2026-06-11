<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormImportFieldMapping extends Model
{
    protected $fillable = [
        'form_import_id',
        'source_field_id',
        'source_field_label',
        'fixed_value',
        'rma_field',
        'append_to_notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'append_to_notes' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function formImport(): BelongsTo
    {
        return $this->belongsTo(FormImport::class);
    }
}
