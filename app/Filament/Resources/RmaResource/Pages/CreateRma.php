<?php

namespace App\Filament\Resources\RmaResource\Pages;

use App\Filament\Resources\RmaResource;
use App\Models\Rma;
use Filament\Resources\Pages\Page;

class CreateRma extends Page
{
    protected static string $resource = RmaResource::class;

    protected static ?string $title = 'RMA aanmaken';

    protected static ?string $breadcrumb = 'RMA aanmaken';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        $rma = Rma::createDraft();

        $this->redirect(RmaResource::getUrl('edit', ['record' => $rma]), navigate: true);
    }
}
