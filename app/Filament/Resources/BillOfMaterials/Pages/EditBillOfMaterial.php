<?php

namespace App\Filament\Resources\BillOfMaterials\Pages;

use App\Filament\Resources\BillOfMaterials\BillOfMaterialResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Html;

class EditBillOfMaterial extends EditRecord
{
    protected static string $resource = BillOfMaterialResource::class;

    protected static ?string $breadcrumb = '';

    public function getTitle(): string
    {
        return $this->getBillOfMaterialHeadingName();
    }

    public function getHeading(): string
    {
        $name = $this->getBillOfMaterialHeadingName();

        return $name !== '-' ? $name : 'Stuklijst bewerken';
    }

    public function getBillOfMaterialHeadingName(): string
    {
        $name = trim((string) ($this->record->name ?? ''));

        return $name !== '' ? $name : '-';
    }

    protected function getFormActions(): array
    {
        return [
            Html::make('<div class="editproduct-footer-actions">'),
                Html::make('<div>'),
                    Action::make('save')
                        ->label('Opslaan')
                        ->action('save'),
                    Action::make('cancel')
                        ->label('Annuleren')
                        ->extraAttributes(['class' => 'white'])
                        ->url(fn () => BillOfMaterialResource::getUrl())
                        ->color('gray'),
                Html::make('</div>'),
                Html::make('<div>'),
                    DeleteAction::make('delete')
                        ->record($this->record)
                        ->requiresConfirmation()
                        ->label('Verwijderen')
                        ->extraAttributes(['class' => 'white color-red-delete'])
                        ->successRedirectUrl(BillOfMaterialResource::getUrl()),
                Html::make('</div>'),
            Html::make('</div>'),
        ];
    }
}
