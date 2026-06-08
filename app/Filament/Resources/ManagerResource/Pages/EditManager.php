<?php

namespace App\Filament\Resources\ManagerResource\Pages;

use App\Filament\Resources\ManagerResource;
use Filament\Resources\Pages\EditRecord;

class EditManager extends EditRecord
{
    protected static string $resource = ManagerResource::class;

    protected static ?string $breadcrumb = 'Aanpassen';

    public function getHeading(): string
    {
        return $this->record->getName();
    }

    public function getManagerHeadingDisplayName(): string
    {
        return $this->record->getName();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role_ids'] = $this->record->roles
            ->where('guard_name', 'web')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['role_ids']);

        return $data;
    }

    protected function afterSave(): void
    {
        $roleIds = $this->form->getRawState()['role_ids'] ?? [];
        $roles = ManagerResource::resolveWebRolesFromSelectState($roleIds);

        $this->record->syncRoles($roles);
    }
}
