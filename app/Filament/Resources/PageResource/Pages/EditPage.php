<?php

namespace App\Filament\Resources\PageResource\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\PageResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    public function form(Schema $schema): Schema
    {
        // Default fields which are always included
        $fields = [
            PageResource::getGeneralPageFields(),
        ];
        $pageTypeId = $this->record->page_type_id;

        // Add fields based on the page type ID
        $fields[] = match ($pageTypeId) {
            1 => PageResource::getHomepageFields(),
            4 => PageResource::getNewsPageFields(),
            9 => PageResource::getNewsItemPageFields(),
            7 => PageResource::getContactPageFields(),
            10 => PageResource::getDemoPageFields(),
            2 => PageResource::getPortalPageFields(),
            3 => PageResource::getForWhomPageFields(),
            5 => PageResource::getAboutUsPageFields(),
            6 => PageResource::getSupportPageFields(),
            8 => PageResource::getConditionsPageFields(),
            11 => PageResource::getDealersAanmeldenPageFields(),
            default => null,
        };

        return $schema
            ->columns(1)
            ->components(Arr::flatten($fields, 2));
    }
}
