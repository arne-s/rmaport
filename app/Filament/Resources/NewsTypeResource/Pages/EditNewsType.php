<?php

namespace App\Filament\Resources\NewsTypeResource\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\NewsTypeResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNewsType extends EditRecord
{
    protected static string $resource = NewsTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
