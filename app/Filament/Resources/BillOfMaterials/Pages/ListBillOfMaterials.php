<?php

namespace App\Filament\Resources\BillOfMaterials\Pages;

use App\Filament\Resources\BillOfMaterials\BillOfMaterialResource;
use Filament\Resources\Pages\ListRecords;

class ListBillOfMaterials extends ListRecords
{
    protected static string $resource = BillOfMaterialResource::class;
    protected static ?string $breadcrumb = 'Stuklijsten';

    public function getDefaultTableRecordsPerPageSelectOption(): int
    {
        return 50;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getBreadcrumbs(): array
    {
        return [
            '' => 'Producten',
            url()->current() => 'Stuklijsten',
        ];
    }
}
