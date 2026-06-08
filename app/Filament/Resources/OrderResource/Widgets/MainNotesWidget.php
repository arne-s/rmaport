<?php

namespace App\Filament\Resources\OrderResource\Widgets;

use App\Enums\NoteType;
use App\Filament\Actions\CreateNoteAction;
use App\Filament\Actions\EditNoteAction;
use App\Filament\Resources\Resource;
use App\Models\Note;
use App\Models\Order\Main;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MainNotesWidget extends TableWidget
{
    protected static ?string $heading = '';

    protected static ?string $model = Note::class;

    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getCreateNoteFormDefaults(): array
    {
        if (! $this->record instanceof Main) {
            return [];
        }

        $main = $this->record;
        $defaults = [
            'attachments_bucket' => (string) Str::uuid(),
            'type' => NoteType::Order->value,
            'order_id' => $main->getId(),
        ];

        if ($main->billing_customer_id !== null) {
            $defaults['customer_id'] = $main->billing_customer_id;
            $defaults['customer_or_company'] = 'customer-' . $main->billing_customer_id;
        } elseif ($main->customer_id !== null) {
            $defaults['customer_id'] = $main->customer_id;
            $defaults['customer_or_company'] = 'customer-' . $main->customer_id;
        }

        return $defaults;
    }

    protected function getTableQuery(): Builder
    {
        if (! $this->record instanceof Main) {
            return Note::query()->whereRaw('1 = 0');
        }

        return Note::query()
            ->whereHas('orders', fn (Builder $q): Builder => $q->whereKey($this->record->getId()))
            ->with(['user', 'customer']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateNoteAction::make()
                    ->label('Notitie')
                    ->fillForm(fn (): array => $this->getCreateNoteFormDefaults()),
            ])
            ->query($this->getTableQuery())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Datum (aangemaakt)')
                    ->dateTime('d-m-Y')
                    ->width('1%')
                    ->sortable(),

                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('author')
                    ->label('Relatie')
                    ->state(fn (Note $record): ?string => $record->author),

                TextColumn::make('content')
                    ->label('Notitie')
                    ->formatStateUsing(function (?string $state): string {
                        $text = $state ? strip_tags($state) : '';

                        return strlen($text) > 50 ? substr($text, 0, 50) . '...' : $text;
                    })
                    ->searchable()
                    ->limit(50),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->getLabel() ?? '-'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->default('open')
                    ->color(fn ($state) => $state?->getColor() ?? 'gray')
                    ->formatStateUsing(fn ($state) => $state?->getLabel() ?? '-'),

                TextColumn::make('user.first_name')
                    ->label('Auteur')
                    ->formatStateUsing(fn ($record) => $record->user ? $record->user->first_name . ' ' . $record->user->last_name : '-'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Resource::getNoteStatusFilter(),
            ], layout: FiltersLayout::AboveContent)
            ->emptyStateHeading('Geen notities voor deze aanvraag')
            ->paginated([10, 25, 50])
            ->extraAttributes(['class' => 'searchAlignLeft'])
            ->recordAction('edit_note')
            ->recordActions([
                EditNoteAction::make('edit_note'),
            ]);
    }
}
