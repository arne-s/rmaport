<?php

namespace App\Livewire;

use App\Models\Note;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class NoteDocumentsPanel extends Component
{
    public ?Note $record = null;

    public string $attachmentsBucket = '';

    public function render(): View
    {
        return view('livewire.note-documents-panel');
    }
}
