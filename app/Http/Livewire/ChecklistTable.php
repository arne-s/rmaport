<?php

namespace App\Http\Livewire;

use App\Models\Order\BaseOrder;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class ChecklistTable extends Component
{
    public int $ownerId;

    public string $ownerClass;

    /**
     * @var list<string>|null When set, overrides config('checklist.default_items').
     */
    public ?array $defaultItems = null;

    /**
     * @var array<int, array{description: string, checked_at: string, checked_by_name: string}>
     */
    public array $rows = [];

    /**
     * @param list<string>|null $defaultItems
     */
    public function mount(int $ownerId, string $ownerClass, ?array $defaultItems = null): void
    {
        $this->ownerId = $ownerId;
        $this->ownerClass = $ownerClass;
        $this->defaultItems = $defaultItems;
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

    protected function loadRowsFromRecord(): void
    {
        $owner = $this->getOwner();
        $defaultDescriptions = $this->defaultItems ?? config('checklist.default_items', []);
        $saved = $owner !== null && is_array($owner->checklist) ? $owner->checklist : [];

        $byDescription = [];
        foreach ($saved as $row) {
            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') {
                continue;
            }

            $byDescription[$description] = [
                'description' => $description,
                'checked_at' => (string) ($row['checked_at'] ?? ($row['date'] ?? '')),
                'checked_by_name' => (string) ($row['checked_by_name'] ?? ''),
            ];
        }

        $this->rows = [];
        foreach ($defaultDescriptions as $description) {
            $this->rows[] = $byDescription[$description] ?? [
                'description' => $description,
                'checked_at' => '',
                'checked_by_name' => '',
            ];
        }
    }

    /**
     * `checked_at` / legacy `date` are stored as `Y-m-d H:i:s` (app timezone); older rows may be date-only `Y-m-d`.
     *
     * @return array<int, array{description: string, date: string, checked_at: string, checked_by_name: string}>
     */
    public function getRowsForPersistence(): array
    {
        $toSave = [];
        foreach ($this->rows as $row) {
            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') {
                continue;
            }

            $checkedAt = trim((string) ($row['checked_at'] ?? ''));
            $checkedByName = trim((string) ($row['checked_by_name'] ?? ''));
            $toSave[] = [
                'description' => $description,
                // Keep legacy `date` key for backwards compatibility.
                'date' => $checkedAt,
                'checked_at' => $checkedAt,
                'checked_by_name' => $checkedByName,
            ];
        }

        return $toSave;
    }

    public function toggleRow(int $index, bool $checked): void
    {
        if (! isset($this->rows[$index])) {
            return;
        }

        if ($this->isFinalCheckRow($this->rows[$index])) {
            return;
        }

        $alreadyChecked = trim((string) ($this->rows[$index]['checked_at'] ?? '')) !== '';
        // Once checked, a row cannot be unchecked again.
        if ($alreadyChecked) {
            return;
        }

        if ($checked) {
            $this->rows[$index]['checked_at'] = Carbon::now()->format('Y-m-d H:i:s');
            $this->rows[$index]['checked_by_name'] = (string) (auth()->user()?->name ?? '');
        }

        $this->syncFinalCheckFromOtherRows();
        $this->persistRowsToRecord();
    }

    protected function isFinalCheckRow(array $row): bool
    {
        return mb_strtolower(trim((string) ($row['description'] ?? ''))) === 'eindcontrole';
    }

    protected function syncFinalCheckFromOtherRows(): void
    {
        $finalIndex = null;
        foreach ($this->rows as $index => $row) {
            if ($this->isFinalCheckRow($row)) {
                $finalIndex = $index;
                break;
            }
        }

        if ($finalIndex === null) {
            return;
        }

        $allChecked = collect($this->rows)
            ->reject(fn (array $row): bool => $this->isFinalCheckRow($row))
            ->every(fn (array $row): bool => trim((string) ($row['checked_at'] ?? '')) !== '');

        if ($allChecked) {
            if (trim((string) ($this->rows[$finalIndex]['checked_at'] ?? '')) === '') {
                $this->rows[$finalIndex]['checked_at'] = Carbon::now()->format('Y-m-d H:i:s');
                $this->rows[$finalIndex]['checked_by_name'] = (string) (auth()->user()?->name ?? '');
            }

            return;
        }

        $this->rows[$finalIndex]['checked_at'] = '';
        $this->rows[$finalIndex]['checked_by_name'] = '';
    }

    protected function persistRowsToRecord(): void
    {
        $owner = $this->getOwner();
        if (! $owner instanceof BaseOrder) {
            return;
        }

        $owner->setChecklist($this->getRowsForPersistence());
        $owner->save();
    }

    public function emitChecklistToParent(): void
    {
        $this->persistRowsToRecord();
        $this->loadRowsFromRecord();
    }

    public function render(): View
    {
        return view('livewire.checklist-table');
    }
}
