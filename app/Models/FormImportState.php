<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormImportState extends Model
{
    protected $fillable = [
        'form_import_id',
        'last_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'last_entry_id' => 'integer',
        ];
    }

    public function formImport(): BelongsTo
    {
        return $this->belongsTo(FormImport::class);
    }
}
