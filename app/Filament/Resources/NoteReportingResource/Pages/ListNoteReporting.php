<?php

namespace App\Filament\Resources\NoteReportingResource\Pages;

use App\Enums\NoteStatus;
use App\Filament\Actions\CreateNoteAction;
use App\Filament\Actions\EditNoteAction;
use App\Filament\Forms\Components\ToggleFilter;
use App\Filament\Resources\NoteReportingResource;
use App\Models\Note;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class ListNoteReporting extends ListRecords
{
    protected static string $resource = NoteReportingResource::class;

    protected static ?string $title = 'Notities';

    protected static ?string $breadcrumb = 'Notities';

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getBreadcrumbs(): array
    {
        return [
            '' => 'Reporting',
            route('filament.app.resources.note-reporting.index') => 'Notities',
        ];
    }

    protected function getTableQuery(): Builder
    {
        return Note::query()
            ->with(['user', 'customer']);
    }

    public function table(Table $table): Table
    {
        return $table
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
                    ->state(fn (Note $record): ?string => $record->author),

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
                    ->formatStateUsing(fn ($state) => $state->getLabel()),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state) => $state?->getColor() ?? 'gray')
                    ->formatStateUsing(fn ($state) => $state->getLabel()),

                TextColumn::make('user.first_name')
                    ->label('Auteur')
                    ->formatStateUsing(fn (Note $record) => $record->user
                        ? $record->user->first_name . ' ' . $record->user->last_name
                        : '-'),
            ])
            ->deferFilters(false)
            ->filters([
                Filter::make('status')
                    ->label('Status')
                    ->indicateUsing(function (array $data): ?string {
                        if (empty($data['status'])) {
                            return null;
                        }
                        $labels = collect($data['status'])
                            ->map(fn (string $v) => NoteStatus::tryFrom($v)?->getLabel() ?? $v)
                            ->join(', ');

                        return 'Status: ' . $labels;
                    })
                    ->schema([
                        ToggleFilter::make()
                            ->label('Status')
                            ->schema([
                                CheckboxList::make('status')
                                    ->label('')
                                    ->options(NoteStatus::labels())
                                    ->default([NoteStatus::Open->value]),
                            ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            ! empty($data['status']),
                            fn (Builder $q) => $q->whereIn('status', $data['status']),
                        )
                    ),
            ], layout: FiltersLayout::AboveContent)
            ->headerActions([
                CreateNoteAction::make()
                    ->label('Notitie aanmaken')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole(['manager', 'Super Admin'])),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordAction(auth()->user()?->hasRole(['manager', 'Super Admin']) ? 'edit_note' : null)
            ->recordActions([
                EditNoteAction::make('edit_note')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole(['manager', 'Super Admin'])),
            ]);
    }

    public function getHeader(): ?View
    {
        return view('filament.components.back-to-overview-with-topbar-breadcrumbs', [
            'title' => 'Dashboard',
            'url' => route('filament.app.pages.dashboard'),
            'class' => 'quote-overview-back mt-4 mb-[-15px]',
            'breadcrumbs' => Filament::hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
        ]);
    }
}
