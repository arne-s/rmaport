<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MainReportingResource\Pages\ListMainReports;
use App\Models\MainReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class MainReportingResource extends Resource
{
    protected static ?string $model = MainReport::class;

    protected static ?string $breadcrumb = 'Reporting';

    protected static ?string $modelLabel = 'Main-rapportage';

    protected static ?string $pluralLabel = 'Main-rapportages';

    protected static ?string $slug = 'main-reporting';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

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

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMainReports::route('/'),
        ];
    }
}
