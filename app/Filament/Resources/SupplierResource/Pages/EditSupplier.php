<?php

namespace App\Filament\Resources\SupplierResource\Pages;

use App\Filament\Concerns\DispatchesExactSyncToastPolling;
use App\Filament\Resources\SupplierResource;
use App\Jobs\SyncSupplierToExactJob;
use App\Models\Supplier;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSupplier extends EditRecord
{
    use DispatchesExactSyncToastPolling;

    protected static string $resource = SupplierResource::class;

    protected static ?string $breadcrumb = '';

    public function getTitle(): string
    {
        return $this->getSupplierHeadingName();
    }

    public function getHeading(): string
    {
        return 'Leverancier: ' . $this->getSupplierHeadingName();
    }

    public function getSupplierHeadingName(): string
    {
        $name = trim((string) ($this->record?->name ?? ''));

        return $name !== '' ? $name : '-';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function afterSave(): void
    {
        /** @var Supplier $supplier */
        $supplier = $this->record;

        if (! $supplier->sync_with_exact) {
            return;
        }

        if (! config('exact.enabled')) {
            Notification::make()
                ->title('Exact-koppeling uitgeschakeld')
                ->warning()
                ->send();
            return;
        }

        SyncSupplierToExactJob::dispatch($supplier->id, auth()->id());
        $this->requestExactSyncToastPolling();
    }
}
