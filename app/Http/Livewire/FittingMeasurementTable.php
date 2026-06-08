<?php

namespace App\Http\Livewire;

use App\Models\Order\BaseOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use Livewire\Component;

class FittingMeasurementTable extends Component
{
    /** Model ID (e.g. main order id). */
    public int $ownerId;

    /** Fully qualified model class (e.g. App\Models\Order\Main). */
    public string $ownerClass;

    /**
     * Rows: list of [code => string, current => string, new => string, custom => bool].
     * @var array<int, array{code: string, current: string, new: string, custom: bool}>
     */
    public array $rows = [];

    public function mount(int $ownerId, string $ownerClass): void
    {
        $this->ownerId = $ownerId;
        $this->ownerClass = $ownerClass;
        $this->loadRowsFromRecord();
    }

    protected function getOwner(): ?Model
    {
        if (! is_subclass_of($this->ownerClass, Model::class)) {
            return null;
        }

        if (is_subclass_of($this->ownerClass, BaseOrder::class)) {
            return $this->ownerClass::withoutGlobalScopes()->find($this->ownerId);
        }

        return $this->ownerClass::find($this->ownerId);
    }

    public function updated(mixed $property): void
    {
        if (! is_string($property) || ! str_starts_with($property, 'rows')) {
            return;
        }

        $this->dispatch('order-view-mark-dirty');
    }

    protected function loadRowsFromRecord(): void
    {
        $owner = $this->getOwner();
        $defaultCodes = config('fitting_measurements.default_codes', []);
        $saved = $owner !== null && is_array($owner->fitting_measurements) ? $owner->fitting_measurements : [];

        $byCode = [];
        $customRows = [];
        foreach ($saved as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = trim((string) ($row['code'] ?? ''));
            $custom = (bool) ($row['custom'] ?? false);
            $normalized = [
                'code' => $code,
                'current' => (string) ($row['current'] ?? ''),
                'new' => (string) ($row['new'] ?? ''),
                'custom' => $custom,
            ];

            if ($custom) {
                $customRows[] = $normalized;
            } else {
                $byCode[$code] = $normalized;
            }
        }

        $this->rows = [];
        foreach ($defaultCodes as $code) {
            $this->rows[] = $byCode[$code] ?? [
                'code' => $code,
                'current' => '',
                'new' => '',
                'custom' => false,
            ];
        }
        foreach ($customRows as $row) {
            $this->rows[] = $row;
        }
    }

    public function addRow(): void
    {
        $this->rows[] = [
            'code' => '',
            'current' => '',
            'new' => '',
            'custom' => true,
        ];

        $this->dispatch('order-view-mark-dirty');
    }

    public function removeRow(int $index): void
    {
        if (! isset($this->rows[$index])) {
            return;
        }
        $row = $this->rows[$index];
        if (! ($row['custom'] ?? false)) {
            return;
        }
        array_splice($this->rows, $index, 1);

        $this->dispatch('order-view-mark-dirty');
    }

    /**
     * Build normalized rows for persistence (same structure as loadRowsFromRecord expects).
     *
     * @return array<int, array{code: string, current: string, new: string, custom: bool}>
     */
    public function getRowsForPersistence(): array
    {
        $toSave = [];
        foreach ($this->rows as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            $custom = (bool) ($row['custom'] ?? false);
            if ($custom && $code === '') {
                continue;
            }
            $toSave[] = [
                'code' => $code,
                'current' => (string) ($row['current'] ?? ''),
                'new' => (string) ($row['new'] ?? ''),
                'custom' => $custom,
            ];
        }

        return $toSave;
    }

    /**
     * Persist maten on the owner record. Footer save calls this on the child first (latest wire:model state),
     * then {@see ViewOrder::saveOrderDetails()} for the rest of the fitting tab.
     */
    public function emitFittingMeasurementsToParent(): void
    {
        $owner = $this->getOwner();

        if (! $owner instanceof BaseOrder) {
            return;
        }

        $owner->setFittingMeasurements($this->getRowsForPersistence());
        $owner->save();

        $this->loadRowsFromRecord();
    }

    #[On('fitting-measurements-reload')]
    public function reloadRowsFromRecord(): void
    {
        $this->loadRowsFromRecord();
    }

    public function render(): View
    {
        return view('livewire.fitting-measurement-table');
    }
}
