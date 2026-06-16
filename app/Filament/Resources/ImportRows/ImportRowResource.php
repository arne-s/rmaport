<?php

namespace App\Filament\Resources\ImportRows;

use App\Filament\Resources\ImportRows\Pages\ListImportRows;
use App\Filament\Resources\ImportRows\Pages\ViewImportRow;
use App\Filament\Resources\ImportRows\Schemas\ImportRowInfolist;
use App\Filament\Resources\ImportRows\Tables\ImportRowsTable;
use App\Filament\Resources\Resource;
use App\Filament\Support\SalesAuthorization;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ImportRowResource extends Resource
{
    protected static ?string $model = ImportRow::class;

    protected static ?string $slug = 'import-rows';

    protected static ?string $modelLabel = 'Importrij';

    protected static ?string $pluralModelLabel = 'Importrijen';

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

    public static function infolist(Schema $schema): Schema
    {
        return ImportRowInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'source.customer', 'importBatch.user', 'importBatch.importTemplate', 'importBatch.export', 'rma']);
    }

    public static function table(Table $table): Table
    {
        return ImportRowsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportRows::route('/'),
            'view' => ViewImportRow::route('/{record}'),
        ];
    }

    public static function indexUrlForImportTask(ImportBatch $batch): string
    {
        return static::getUrl('index', [
            'search' => $batch->uid,
        ]);
    }
}
