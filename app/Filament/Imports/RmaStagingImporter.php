<?php

namespace App\Filament\Imports;

use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Model;

/**
 * Placeholder importer class for RMA staging imports stored on the Filament imports table.
 */
class RmaStagingImporter extends Importer
{
    protected static ?string $model = null;

    public static function getColumns(): array
    {
        return [];
    }

    public function resolveRecord(): ?Model
    {
        return null;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return 'Import voltooid.';
    }
}
