<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Support\RecordLockNavigation;
use App\Models\Order\Order;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Lightweight order view page bound to a real Order record.
 */
class ViewOrderRecord extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return Order::withoutGlobalScopes()->findOrFail($key);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->action(function (): void {
                    /** @var Order $record */
                    $record = $this->getRecord();

                    RecordLockNavigation::attemptRedirectToEdit(
                        $this,
                        $record,
                        OrderResource::getUrl('edit', ['record' => $record]),
                    );
                }),
        ];
    }
}
