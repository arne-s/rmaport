<?php

namespace App\Livewire;

use App\Filament\Actions\EditNoteAction;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Attributes\On;
use Livewire\Component;

class GlobalEditNote extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public function edit_noteAction(): Action
    {
        return EditNoteAction::make('edit_note')
            ->modalHeading(fn (array $arguments) => 'Notitie | #' . ($arguments['noteId'] ?? ''));
    }

    #[On('open-edit-note')]
    public function openEditNote(int $noteId): void
    {
        $this->mountAction('edit_note', ['noteId' => $noteId]);

        $this->dispatch('close-modal', id: 'database-notifications');
    }

    public function render()
    {
        return view('livewire.global-edit-note');
    }
}
