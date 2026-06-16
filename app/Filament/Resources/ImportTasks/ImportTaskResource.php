<?php

namespace App\Filament\Resources\ImportTasks;

use App\Filament\Resources\ImportTasks\Pages\ListImportTasks;
use App\Filament\Resources\ImportTasks\Tables\ImportTasksTable;
use App\Filament\Resources\Resource;
use App\Filament\Support\SalesAuthorization;
use App\Models\ImportBatch;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ImportTaskResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static ?string $slug = 'import-tasks';

    protected static ?string $modelLabel = 'Importtaak';

    protected static ?string $pluralModelLabel = 'Importtaken';

    protected static ?string $breadcrumb = 'Retouren';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    public static function canViewAny(): bool
    {
        return SalesAuthorization::canManage();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'user',
                'importRows.customer',
                'importRows.source.customer',
            ]);
    }

    public static function table(Table $table): Table
    {
        return ImportTasksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportTasks::route('/'),
        ];
    }

    public static function indexUrlForImportTask(ImportBatch $batch): string
    {
        return static::getUrl('index', [
            'search' => $batch->uid,
        ]);
    }
}
