<?php

namespace App\Filament\Resources\RmaResource\Pages;

use App\Filament\Resources\RmaResource;
use App\Models\Rma;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class EditRma extends EditRecord
{
    protected static string $resource = RmaResource::class;

    protected static ?string $breadcrumb = '';

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        $record = $this->record;
        if (! $record instanceof Rma || ! $record->exists) {
            return parent::getBreadcrumbs();
        }

        return [
            RmaResource::getUrl('index') => 'Retouren',
            RmaResource::getUrl('view', ['record' => $record]) => $this->getRmaHeadingUid(),
            RmaResource::getUrl('edit', ['record' => $record]) => 'Bewerken',
        ];
    }

    public function form(Schema $schema): Schema
    {
        return RmaResource::editForm($schema);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getRmaHeadingUid();
    }

    public function getHeading(): string
    {
        return 'RMA: '.$this->getRmaHeadingUid();
    }

    public function getRmaHeadingUid(): string
    {
        /** @var Rma $record */
        $record = $this->record;

        $uid = trim((string) ($record->uid ?? ''));

        return $uid !== '' ? $uid : '-';
    }

    protected function resolveRecord(int|string $key): Rma
    {
        return Rma::query()->findOrFail($key);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record instanceof Rma && $this->record->is_draft) {
            $data['is_draft'] = false;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<\Filament\Actions\Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
        ];
    }
}
