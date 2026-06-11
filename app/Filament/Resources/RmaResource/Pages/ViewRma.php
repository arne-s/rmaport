<?php

namespace App\Filament\Resources\RmaResource\Pages;

use App\Filament\Resources\RmaResource;
use App\Filament\Resources\RmaResource\Actions\SendRmaEmailAction;
use App\Filament\Resources\RmaResource\Pages\Traits\RmaStatusTrait;
use App\Filament\Resources\RmaResource\Support\RmaRelatedMainResolver;
use App\Enums\RmaAssessment;
use App\Models\Order\Main;
use App\Models\Rma;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rule;

/**
 * @property Rma $record
 */
class ViewRma extends ViewRecord implements HasActions
{
    use InteractsWithActions;
    use RmaStatusTrait;

    protected static string $resource = RmaResource::class;

    protected string $view = 'filament.resources.rmas.pages.view-rma';

    public ?string $service = null;

    /** Interne notities (kolom rmas.notes) — niet te verwarren met tab Notities. */
    public ?string $internalNotes = null;

    public ?string $assessment = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->loadMissing('customer');
        $this->rmaStatus = $this->record->status?->value;
        $this->service = $this->record->service;
        $this->internalNotes = $this->record->notes;
        $this->assessment = $this->record->assessment?->value;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getRmaViewHeading();
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getRmaViewHeading();
    }

    public function getRmaViewHeading(): string
    {
        $uid = trim((string) ($this->record->uid ?? ''));

        if ($uid === '') {
            return 'RMA: -';
        }

        $customerName = trim((string) ($this->record->customer?->getName() ?? ''));

        if ($customerName === '') {
            return 'RMA: '.$uid;
        }

        return 'RMA: '.$uid.' | '.$customerName;
    }

    protected function resolveRecord(int|string $key): Rma
    {
        return Rma::query()->with('customer')->findOrFail($key);
    }

    public function getRelatedMain(): ?Main
    {
        return RmaRelatedMainResolver::resolve($this->record);
    }

    public function saveRmaWorkNotes(): void
    {
        $this->validate([
            'service' => ['nullable', 'string'],
            'internalNotes' => ['nullable', 'string'],
            'assessment' => ['nullable', Rule::enum(RmaAssessment::class)],
        ]);

        $changed = $this->record->service !== $this->service
            || $this->record->notes !== $this->internalNotes
            || $this->record->assessment?->value !== ($this->assessment ?: null);

        $this->record->update([
            'service' => $this->service,
            'notes' => $this->internalNotes,
            'assessment' => $this->assessment ?: null,
        ]);

        if ($changed) {
            $this->record->logEvent('Werkzaamheden/notities bijgewerkt');
        }

        Notification::make()
            ->title('Opgeslagen')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            SendRmaEmailAction::make(),
            Action::make('save')
                ->label('Opslaan')
                ->color('primary')
                ->action(fn (): mixed => $this->saveRmaWorkNotes()),
        ];
    }

    /**
     * @return array<int, Action>
     */
    public function getHeaderActionsForView(): array
    {
        return $this->getHeaderActions();
    }

    public function sendRmaEmailAction(): SendRmaEmailAction
    {
        return SendRmaEmailAction::make();
    }
}
