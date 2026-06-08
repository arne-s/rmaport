<?php

namespace App\Filament\Widgets;

use App\Enums\NoteStatus;
use App\Enums\NoteType;
use App\Filament\Actions\CreateNoteAction;
use App\Filament\Actions\EditNoteAction;
use App\Filament\Resources\NoteResource;
use App\Models\Note;
use App\Models\Order\Main;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class NotesWidget extends TableWidget
{
    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = 4;

    protected string $view = 'filament.widgets.notes-widget';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Mijn notities')
            ->headerActions([
                CreateNoteAction::make()
                    ->label('Notitie'),
            ])
            ->query(fn(): Builder => Note::query()
                ->with(['user', 'customer'])
                ->where(function ($query) {
                    $query->where('user_id', auth()->id())
                        ->orWhereHas('users', function ($q) {
                            $q->where('users.id', auth()->id());
                        });
                })
                ->whereIn('status', [NoteStatus::Open->value, NoteStatus::Ongoing->value])
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('Datum')
                    ->dateTime('d-m-Y')
                    ->sortable(),

                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('author')
                    ->label('Klantnaam')
                    ->state(fn(Note $record): ?string => $record->author),

                TextColumn::make('content')
                    ->label('Notitie')
                    ->formatStateUsing(function (?string $state): string {
                        $text = $state ? strip_tags($state) : '';
                        return strlen($text) > 30 ? substr($text, 0, 30) . '...' : $text;
                    })
                    ->searchable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state->getLabel()),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn($state) => $state?->getColor() ?? 'gray')
                    ->formatStateUsing(fn($state) => $state->getLabel()),

                TextColumn::make('user.first_name')
                    ->label('Auteur')
                    ->formatStateUsing(fn($record) => $record->user->first_name . ' ' . $record->user->last_name),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Geen notities')
            ->recordAction('edit_note')
            ->recordActions([
                EditNoteAction::make('edit_note'),
            ]);
    }
}
