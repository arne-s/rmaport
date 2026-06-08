<?php

namespace App\Filament\Resources\BillOfMaterials\Pages;

use App\Filament\Resources\BillOfMaterials\BillOfMaterialResource;
use App\Filament\Resources\Resource;
use Filament\Resources\Pages\CreateRecord;

class CreateBillOfMaterial extends CreateRecord
{
    protected static string $resource = BillOfMaterialResource::class;
    protected static bool $canCreateAnother = false;
    protected static ?string $breadcrumb = 'Stuklijst aanmaken';

    public function getHeading(): string
    {
        return 'Nieuwe stuklijst';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] ??= auth()->id();

        return $data;
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSubmitFormAction(),

            $this->getCancelFormAction()
                ->action(function (): void {
                    $redirectUrl = Resource::getRedirectToMainUrlForRecord($this->record);
                    if ($redirectUrl !== null) {
                        $this->redirect($redirectUrl, navigate: true);
                    } else {
                        $this->redirect(route('filament.app.resources.bill-of-materials.index'));
                    }
                })
                ->extraAttributes(['class' => 'white']),
        ];
    }
}
