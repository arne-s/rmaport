<?php

namespace App\Filament\Actions;

use App\Enums\NoteType;
use App\Filament\Resources\NoteResource;
use App\Livewire\NotePendingAttachmentsUpload;
use App\Models\Note;
use App\Models\Order\Main;
use App\Models\Product;
use App\Models\User;
use App\View\Components\PanelNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateNoteAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'create_note';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Notitie')
            ->icon('heroicon-s-plus-circle')
            ->modalHeading('Nieuwe Notitie')
            ->closeModalByEscaping(false)
            ->closeModalByClickingAway(false)
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Opslaan')
            ->extraModalWindowAttributes(['class' => 'note-modal'])
            ->schema(fn () => NoteResource::getFormSchema())
            ->action(function (array $data) {
                $taggedUsers = collect();
                /** @var ?Note $note */
                $note = null;

                DB::transaction(function () use ($data, &$taggedUsers, &$note) {
                    // Prepare additional data
                    $additional = null;
                    if ($data['type'] === NoteType::Callback->value && !empty($data['callback_time'])) {
                        $additional = ['callback_time' => $data['callback_time']];
                    }

                    $note = Note::create([
                        'type' => $data['type'],
                        'status' => $data['status'] ?? 'open',
                        'content' => $data['content'] ?? null,
                        'user_id' => auth()->id(),
                        'customer_id' => $data['customer_id'] ?? null,
                        'additional' => $additional,
                    ]);

                    // Attach order as polymorphic relation
                    if ($data['type'] === NoteType::Order->value && !empty($data['order_id'])) {
                        $order = Main::find($data['order_id']);
                        if ($order) {
                            $note->orders()->attach($order);
                        }
                    }

                    // Resolve tagged users once
                    $emails = collect($data['tagged_users'] ?? [])
                        ->map(function ($userName) {
                            preg_match('/\((.*?)\)/', $userName, $matches);

                            return $matches[1] ?? null;
                        })
                        ->filter()
                        ->unique()
                        ->values();

                    $taggedUsers = User::whereIn('email', $emails)->get();

                    if ($taggedUsers->isNotEmpty()) {
                        $note->users()->attach($taggedUsers->pluck('id')->all());
                    }

                    $bucket = $data['attachments_bucket'] ?? '';
                    $pendingRaw = $bucket !== ''
                        ? Cache::pull('note_pending_attachments.'.$bucket, [])
                        : [];
                    $pendingEntries = NotePendingAttachmentsUpload::normalizeCachePayload($pendingRaw);

                    foreach ($pendingEntries as $entry) {
                        $path = $entry['path'];
                        $disk = Storage::disk('public');
                        if ($path === '' || ! $disk->exists($path)) {
                            continue;
                        }

                        $add = $note->addMedia($disk->path($path));
                        if ($entry['original_name'] !== '') {
                            $add = $add->usingFileName($entry['original_name']);
                        }
                        $add->toMediaCollection('attachments');
                    }
                });

                $shortContent = $note->getContent() ? strip_tags($note->getContent()) : '';
                $shortContent = strlen($shortContent) > 30 ? substr($shortContent, 0, 30) . '...' : $shortContent;
                $subject = $note->customer?->getName() ?? '-';

                foreach ($taggedUsers as $user) {
                    PanelNotification::make()
                        ->title('Getagd aan notitie (#' . $note->getId() . ')')
                        ->icon('heroicon-s-chat-bubble-bottom-center-text')
                        ->body(
                            'Type: ' . $note->getType()?->getLabel()
                            . '<br>Klant: ' . $subject
                            . '<br>Inhoud: ' . $shortContent
                        )
                        ->actions([
                            Action::make('click')
                                ->alpineClickHandler("\$dispatch('open-edit-note', { noteId: " . $note->getId() . " })"),
                        ])
                        ->sendToDatabase($user);
                }

                Notification::make()
                    ->title('Notitie opgeslagen')
                    ->success()
                    ->send();
            })
            ->modalCancelAction(false);
    }
}
