<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SerialNumbersResource\Pages\ListSerialNumbers;
use App\Models\SerialNumber;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class SerialNumbersResource extends Resource
{
    protected static ?string $model = SerialNumber::class;

    protected static ?string $breadcrumb = 'Reporting';

    protected static ?string $modelLabel = 'Serienummers';

    protected static ?string $pluralLabel = 'Serienummers';

    protected static ?string $navigationLabel = 'Serienummers';

    protected static ?string $slug = 'serial-numbers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

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
            'index' => ListSerialNumbers::route('/'),
        ];
    }
}
