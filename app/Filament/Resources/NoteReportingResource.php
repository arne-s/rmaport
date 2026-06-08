<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NoteReportingResource\Pages\ListNoteReporting;
use App\Models\Note;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

class NoteReportingResource extends Resource
{
    protected static ?string $model = Note::class;

    protected static ?string $breadcrumb = 'Reporting';

    protected static ?string $modelLabel = 'Notitie';

    protected static ?string $pluralModelLabel = 'Notities';

    protected static ?string $slug = 'note-reporting';

    protected static bool $shouldRegisterNavigation = false;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage reporting') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNoteReporting::route('/'),
        ];
    }
}
