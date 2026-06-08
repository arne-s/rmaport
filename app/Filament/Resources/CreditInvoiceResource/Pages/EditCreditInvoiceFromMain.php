<?php

namespace App\Filament\Resources\CreditInvoiceResource\Pages;

use App\Filament\Resources\CreditInvoiceResource;
use App\Models\Order\CreditInvoice;

class EditCreditInvoiceFromMain extends EditCreditInvoice
{
    protected static string $resource = CreditInvoiceResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->can('manage financials') ?? false, 403);
    }

    protected function resolveRecord($key): CreditInvoice
    {
        return CreditInvoice::findOrFail($key);
    }

    public function getBreadcrumbs(): array
    {
        $mainId = $this->record->main_id;

        return [
            route('filament.app.resources.mains.view', ['record' => $mainId]) => 'Terug naar Aanvraag',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('filament.app.resources.mains.view', ['record' => $this->record->main_id]);
    }

    protected function getRecordLockBackUrl(): string
    {
        return $this->getRedirectUrl();
    }
}
