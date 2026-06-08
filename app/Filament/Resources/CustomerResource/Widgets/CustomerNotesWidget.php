<?php

namespace App\Filament\Resources\CustomerResource\Widgets;

use App\Filament\Actions\CreateNoteAction;
use App\Filament\Actions\EditNoteAction;
use App\Filament\Resources\Resource;
use App\Models\Customer;
use App\Models\Note;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CustomerNotesWidget extends TableWidget
{
    protected static ?string $heading = '';

    protected static ?string $model = Note::class;

    public ?Model $record = null;

    protected function getTableQuery(): Builder
    {
        $customerId = $this->record instanceof Customer ? $this->record->id : null;

        if ($customerId === null) {
            return Note::query()->whereRaw('1 = 0');
        }

        return Note::query()
            ->where('customer_id', $customerId)
            ->with(['user', 'customer']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateNoteAction::make()
                    ->label('Notitie')
                    ->fillForm(fn () => [
                        'customer_id' => $this->record?->id,
                        'customer_or_company' => $this->record ? 'customer-' . $this->record->id : null,
                    ]),
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
                    ->label('Klantnaam')
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
            ->emptyStateHeading('Geen notities voor deze klant')
            ->paginated([10, 25, 50])
            ->extraAttributes(['class' => 'searchAlignLeft'])
            ->recordAction('edit_note')
            ->recordActions([
                EditNoteAction::make('edit_note'),
            ]);
    }
}
