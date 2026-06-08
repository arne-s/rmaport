<?php

namespace App\Filament\Resources\SupplierResource\Pages;

use App\Filament\Concerns\DispatchesExactSyncToastPolling;
use App\Filament\Resources\SupplierResource;
use App\Jobs\SyncSupplierToExactJob;
use App\Models\Supplier;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplier extends CreateRecord
{
    use DispatchesExactSyncToastPolling;

    protected static string $resource = SupplierResource::class;
    public static bool $canCreateAnother = false;

    protected function afterCreate(): void
    {
        /** @var Supplier $supplier */
        $supplier = $this->record;
        $supplier->sync_with_exact = true;
        $supplier->save();

        if (! config('exact.enabled')) {
            Notification::make()
                ->title('Exact-koppeling uitgeschakeld')
                ->warning()
                ->send();
            return;
        }

        SyncSupplierToExactJob::dispatch($supplier->id, auth()->id());
        $this->requestExactSyncToastPollingAfterRedirect();
    }
}
