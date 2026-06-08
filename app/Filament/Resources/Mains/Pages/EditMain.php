<?php

namespace App\Filament\Resources\Mains\Pages;

use App\Filament\Resources\Mains\MainResource;
use App\Models\Order\Main;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMain extends EditRecord
{
    protected static string $resource = MainResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->redirect(route('filament.app.resources.mains.view', ['record' => $record]));
    }

    protected function resolveRecord(int|string $key): \Illuminate\Database\Eloquent\Model
    {
        return Main::query()->findOrFail($key);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
