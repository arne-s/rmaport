<?php

namespace App\Filament\Resources;

use Filament\Tables\Table;
use Filament\Actions\EditAction;
use App\Enums\EmailTemplateAudience;
use App\Enums\EmailTemplateType;
use App\Filament\Resources\EmailTemplateResource\Pages\ListEmailTemplates;
use App\Filament\Resources\EmailTemplateResource\Pages\CreateEmailTemplate;
use App\Filament\Resources\EmailTemplateResource\Pages\EditEmailTemplate;
use App\Models\EmailTemplate;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EmailTemplateResource extends Resource
{
    protected static ?string $breadcrumb = 'Contentbeheer';

    protected static ?string $modelLabel = 'E-mail';
    protected static ?string $pluralLabel = 'E-mails';
    protected static ?string $slug = 'email-templates';

    protected static ?string $model = EmailTemplate::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage settings') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function table(Table $table): Table
    {
        return $table

            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back',
            ]))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->formatStateUsing(fn (?EmailTemplateType $state): string => $state instanceof EmailTemplateType ? $state->getLabel() : '–')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $types = implode("','", array_keys(EmailTemplateType::labels()));
                        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

                        return $query
                            ->orderByRaw("FIELD(type, '{$types}') {$direction}")
                            ->orderBy('name', 'asc');
                    }),
                TextColumn::make('audience')
                    ->label('Bereik')
                    ->formatStateUsing(fn (?EmailTemplateAudience $state): string => $state instanceof EmailTemplateAudience ? $state->getLabel() : '–')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Naam')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Omschrijving')
                    ->searchable()
                    ->sortable(),
            ])
            ->searchPlaceholder('Zoeken')
            ->deferFilters(false)
            ->filters(
                [
                    self::createStatusFilter('template_type', 'type', 'Type', EmailTemplateType::labels()),
                    self::createStatusFilter('template_audience', 'audience', 'Bereik', EmailTemplateAudience::labels()),
                ],
                layout: FiltersLayout::AboveContent,
            )
            ->defaultSort('type', 'asc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->extraAttributes(['class' => 'searchAlignLeft']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailTemplates::route('/'),
            'create' => CreateEmailTemplate::route('/create'),
            'edit' => EditEmailTemplate::route('/{record}/edit'),
        ];
    }
}
