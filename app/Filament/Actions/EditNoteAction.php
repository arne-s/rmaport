<?php

namespace App\Filament\Actions;

use App\Models\Note;
use Filament\Actions\EditAction;

class EditNoteAction extends EditAction
{
    public static function getDefaultName(): ?string
    {
        return 'edit_note';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Bewerken')
            ->extraAttributes(['class' => 'hidden-note-edit-btn'])
            ->modalHeading(function (?Note $record, array $arguments): string {
                $noteId = $record?->getId() ?? ($arguments['noteId'] ?? '');

                return 'Notitie | #' . $noteId;
            })
            ->closeModalByEscaping(false)
            ->closeModalByClickingAway(false)
            ->modalWidth('2xl')
            ->modalSubmitAction(false)
            ->modalFooterActions([])
            ->modalCancelAction(false)
            ->extraModalWindowAttributes(['class' => 'note-modal'])
            ->modalContent(function (?Note $record, array $arguments) {
                if (($arguments['noteId'] ?? null) !== null) {
                    $record = Note::query()->find($arguments['noteId']);
                }

                if ($record === null) {
                    return null;
                }

                return view('filament.notes.view-note', [
                    'noteId' => $record->id,
                ]);
            });
    }
}
