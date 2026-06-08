<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Enums\OrderGeneralStatus;
use App\Filament\Resources\InvoiceResource;
use App\Models\Order\Invoice;
use Illuminate\Database\Eloquent\Model;

class EditInvoiceFromMain extends EditInvoice
{
    protected static string $resource = InvoiceResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        $invoice = Invoice::withoutGlobalScopes()->findOrFail($key);

        if ($invoice->getStatus() !== OrderGeneralStatus::Initial) {
            abort(403);
        }

        return $invoice;
    }

    protected function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->can('manage financials') ?? false, 403);

        abort_unless(
            $this->record instanceof Invoice && $this->record->getStatus() === OrderGeneralStatus::Initial,
            403
        );
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

    protected function getBackToOverviewTitle(): string
    {
        return 'Aanvraag';
    }

    protected function getBackToOverviewUrl(): string
    {
        return route('filament.app.resources.mains.view', ['record' => $this->record->main_id]);
    }

    protected function getRecordLockBackUrl(): string
    {
        return $this->getBackToOverviewUrl();
    }

    protected function isMainIdDisabled(): bool
    {
        return true;
    }
}
