<?php

namespace App\Traits;

use App\Models\Note;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasNotes
{
    /**
     * Get all of the notes for the model.
     */
    public function notes(): MorphToMany
    {
        return $this->morphToMany(Note::class, 'model', 'model_has_notes')
            ->withTimestamps()
            ->orderBy('created_at', 'desc');
    }
}
