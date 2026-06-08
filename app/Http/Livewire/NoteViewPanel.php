<?php

namespace App\Http\Livewire;

use App\Enums\NoteStatus;
use App\Enums\NoteType;
use App\Models\Note;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class NoteViewPanel extends Component
{
    public int $noteId;

    public string $status = '';

    public string $comment = '';

    public function mount(int $noteId): void
    {
        $this->noteId = $noteId;

        $note = $this->getNote();
        $this->status = $note?->status?->value ?? NoteStatus::Open->value;
    }

    public function updatedStatus(string $value): void
    {
        if (! in_array($value, array_keys(NoteStatus::labels()), true)) {
            return;
        }

        $note = $this->getNote();
        if ($note === null) {
            return;
        }

        $note->update(['status' => $value]);

        Notification::make()
            ->title('Status bijgewerkt')
            ->success()
            ->send();
    }

    public function saveComment(): void
    {
        $validated = $this->validate([
            'comment' => ['required', 'string'],
        ], [
            'comment.required' => 'Vul een reactie in.',
        ]);
        $comment = $this->normalizeCommentLineBreaks($validated['comment']);
        if ($comment === '') {
            $this->addError('comment', 'Vul een reactie in.');

            return;
        }

        $note = $this->getNote();
        if ($note === null || auth()->id() === null) {
            return;
        }

        $note->comments()->create([
            'user_id' => auth()->id(),
            'comment' => $comment,
        ]);

        $this->comment = '';

        Notification::make()
            ->title('Reactie geplaatst')
            ->success()
            ->send();
    }

    public function render(): View
    {
        $note = $this->getNote();

        return view('livewire.note-view-panel', [
            'note' => $note,
            'order' => $note?->orders()->first(),
            'statusOptions' => NoteStatus::labels(),
            'isOrderRelated' => $note?->type === NoteType::Order,
            'comments' => $note?->comments()->with(['user.media'])->latest()->get() ?? collect(),
            'attachments' => $note?->getMedia('attachments') ?? collect(),
            'taggedColleaguesLabel' => $note?->users
                ->map(fn ($user) => $user->getName())
                ->filter()
                ->implode(', ') ?: '-',
        ]);
    }

    private function getNote(): ?Note
    {
        return Note::query()
            ->with(['customer', 'user', 'users', 'comments.user.media'])
            ->find($this->noteId);
    }

    /**
     * Allow at most one blank line between text (collapse 3+ consecutive newlines to 2).
     */
    private function normalizeCommentLineBreaks(string $comment): string
    {
        $comment = str_replace(["\r\n", "\r"], "\n", $comment);
        $comment = preg_replace('/\n{3,}/', "\n\n", $comment) ?? $comment;

        return trim($comment);
    }
}
