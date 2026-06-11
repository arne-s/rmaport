<?php

namespace App\Filament\Resources\RmaResource\Pages\Traits;

use App\Enums\RmaStatus;
use App\Models\Rma;
use App\Models\RmaEvent;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * @property Rma $record
 */
trait RmaStatusTrait
{
    #[Url(as: 'tab')]
    public string $rmaViewTab = 'general';

    public ?string $rmaStatus = null;

    public function updatedRmaStatus(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $status = RmaStatus::tryFrom($value);
        if ($status === null) {
            return;
        }

        $this->record->changeStatus($status);

        Notification::make()
            ->title('Status bijgewerkt')
            ->success()
            ->send();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function getRmaStatusDropdownOptions(): array
    {
        return collect(RmaStatus::cases())
            ->map(fn (RmaStatus $status): array => [
                'value' => $status->value,
                'label' => $status->getLabel() ?? $status->value,
            ])
            ->all();
    }

    /**
     * @return array<int, array{status: RmaStatus, label: string, date: mixed, changedByUserName: ?string, isCurrent: bool}>
     */
    public function getRmaStatusTimeline(): array
    {
        $currentStatus = $this->record->status;
        $statusChanges = $this->record->statusChanges()->with('changedBy')->get();

        $items = [];
        foreach (RmaStatus::cases() as $status) {
            $change = $statusChanges
                ->filter(fn ($statusChange): bool => $statusChange->to_status === $status->value)
                ->sortByDesc('created_at')
                ->first();

            $items[] = [
                'status' => $status,
                'label' => $status->getLabel() ?? $status->value,
                'date' => $change?->created_at,
                'changedByUserName' => $change?->changedBy?->name,
                'isCurrent' => $currentStatus === $status,
            ];
        }

        return $items;
    }

    /**
     * @return Collection<int, RmaEvent>
     */
    public function getRmaEventsForHistory(): Collection
    {
        return $this->record->rmaEvents()->with('user')->orderByDesc('id')->get();
    }
}
