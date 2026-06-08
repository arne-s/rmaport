<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    public function getPageClasses(): array
    {
        return array_merge(parent::getPageClasses(), ['page-edit-profile']);
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getTitle(): string
    {
        return '';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = RoleResource::ensureWebGuardName($data);
        $data['name'] = RoleResource::generateInternalNameFromDisplayName($data['display_name'] ?? null);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return RoleResource::getUrl('index');
    }
}
