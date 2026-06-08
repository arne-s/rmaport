<?php

namespace App\Filament\Resources\NewsTypeResource\Pages;

use App\Filament\Resources\NewsTypeResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateNewsType extends CreateRecord
{
    protected static string $resource = NewsTypeResource::class;
}
