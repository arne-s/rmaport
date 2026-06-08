<?php

namespace App\Filament\Resources\OrderResource\Pages\Concerns;

use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

trait TracksViewOrderUnsavedChanges
{
    #[Locked]
    public bool $isOrderViewDirty = false;

    /**
     * Properties that may change without user edits (poll, UI sync, etc.).
     *
     * @return list<string>
     */
    protected function orderViewDirtyExcludedProperties(): array
    {
        return [
            'isOrderViewDirty',
            'orderViewTab',
            'orderDocsVersion',
            'financialDocsSignature',
            'orderStatus',
            'orderStatusFromDb',
            'showFittingCancelledConfirm',
            'showPassingCompleteConfirm',
            'showPickCompleteReadyForAssemblyModal',
            'confirmOrderProductRecord',
            'fittingCancelledReason',
        ];
    }

    public function markOrderViewDirty(): void
    {
        if (! empty($this->mountedActions)) {
            return;
        }

        if (property_exists($this, 'mountedFormComponentActions') && ! empty($this->mountedFormComponentActions)) {
            return;
        }

        $this->isOrderViewDirty = true;

        if (
            (property_exists($this, 'showPassingCompleteConfirm') && $this->showPassingCompleteConfirm)
            || (property_exists($this, 'showFittingCancelledConfirm') && $this->showFittingCancelledConfirm)
            || (property_exists($this, 'showOrderApprovedConfirm') && $this->showOrderApprovedConfirm)
            || (property_exists($this, 'showPickCompleteReadyForAssemblyModal') && $this->showPickCompleteReadyForAssemblyModal)
        ) {
            return;
        }

        // Avoid re-rendering the order view on dirty-only updates; nested components (e.g. Maten)
        // would remount and lose in-progress wire:model state.
        $this->skipRender();
    }

    public function clearOrderViewDirty(): void
    {
        $this->isOrderViewDirty = false;

        $this->dispatch('order-view-dirty-cleared');
    }

    #[On('order-view-mark-dirty')]
    public function onOrderViewMarkDirty(): void
    {
        $this->markOrderViewDirty();
    }

    public function updated(mixed $property): void
    {
        if (! is_string($property)) {
            return;
        }

        if (in_array($property, $this->orderViewDirtyExcludedProperties(), true)) {
            return;
        }

        $this->markOrderViewDirty();
    }
}
