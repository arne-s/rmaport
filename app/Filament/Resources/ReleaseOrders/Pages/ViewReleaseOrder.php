<?php

namespace App\Filament\Resources\ReleaseOrders\Pages;

use App\Enums\OrderProductStatus;
use App\Enums\ReleaseOrderStatus;
use App\Filament\Resources\ReleaseOrders\ReleaseOrderResource;
use App\Models\OrderProduct;
use App\Models\ReleaseOrder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

/**
 * @property ReleaseOrder $record
 */
class ViewReleaseOrder extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    /** @var class-string<\App\Filament\Resources\ReleaseOrders\ReleaseOrderResource> */
    protected static string $resource = ReleaseOrderResource::class;

    protected static ?string $title = 'Afroepverzoek';

    protected string $view = 'filament.resources.release-orders.pages.view-release-order';

    public ?string $releaseOrderStatus = null;

    public ?OrderProduct $confirmOrderProductRecord = null;

    /** When set, back link goes to this order (main) view with purchase tab. */
    public ?int $returnToOrderId = null;

    public bool $releaseOrderIsOrphaned = false;

    public function mount(int|string $record): void
    {
        static::authorizeResourceAccess();

        $this->record = $this->resolveRecord($record);

        $returnToOrder = request()->query('return_to_order');
        $this->returnToOrderId = $returnToOrder !== null && $returnToOrder !== '' ? (int) $returnToOrder : null;

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $this->releaseOrderIsOrphaned = ! $this->record->hasLinkedOrderProducts();
        $this->releaseOrderStatus = $this->record->getStatus()?->value;
    }

    /**
     * @return list<array{value: string, label: string, selectable: bool}>
     */
    public function getReleaseOrderStatusDropdownOptions(): array
    {
        $current = $this->record->getStatus() ?? ReleaseOrderStatus::Purchased;
        if ($current === ReleaseOrderStatus::Initial) {
            $current = ReleaseOrderStatus::Purchased;
        }

        $categoryOrder = array_flip(['Afroep', 'Op locatie', 'Geannuleerd']);
        $selectableMap = ReleaseOrderStatus::selectableStatuses();
        $raw = [];
        foreach (ReleaseOrderStatus::allWithCategoriesForSelect() as $value => $item) {
            $cat = (string) ($item['category'] ?? '');
            $status = $item['status'] ?? ReleaseOrderStatus::tryFrom($value);
            $optionLabel = $item['label'] ?? $status?->getLabel() ?? $value;
            $next = $this->nextReleaseOrderStatus($current);
            $selectable = $value === $current->value
                || (($selectableMap[$value] ?? true) && $next !== null && $value === $next->value);

            $raw[] = [
                'value' => $value,
                'label' => $optionLabel,
                'selectable' => $selectable,
                'cat_order' => $categoryOrder[$cat] ?? 99,
            ];
        }

        usort($raw, fn (array $a, array $b): int => $a['cat_order'] <=> $b['cat_order']);

        return array_map(fn (array $row): array => [
            'value' => $row['value'],
            'label' => $row['label'],
            'selectable' => $row['selectable'],
        ], $raw);
    }

    public function updatedReleaseOrderStatus(): void
    {
        $this->record->refresh();
        $dbValue = $this->record->getStatus()?->value;
        if ($dbValue === $this->releaseOrderStatus || $this->releaseOrderStatus === null) {
            return;
        }

        $incoming = (string) $this->releaseOrderStatus;
        $selectableMap = ReleaseOrderStatus::selectableStatuses();
        if (! ($selectableMap[$incoming] ?? true) && $incoming !== $dbValue) {
            $this->releaseOrderStatus = $dbValue;

            return;
        }

        $newStatus = ReleaseOrderStatus::tryFrom($incoming);
        if ($newStatus === null) {
            $this->releaseOrderStatus = $dbValue;

            return;
        }
        if (! $this->canMoveToNextReleaseOrderStatus($this->record->getStatus(), $newStatus)) {
            $this->releaseOrderStatus = $dbValue;

            return;
        }

        if ($newStatus === ReleaseOrderStatus::Delivered) {
            $this->record->loadMissing('orderProducts');
            if ($this->record->orderProductsAreAllPickedReceived()) {
                $this->record->setStatus(ReleaseOrderStatus::Delivered);
                $this->record->save();
                $this->record->refresh();
                $this->releaseOrderStatus = $this->record->getStatus()?->value;
                $this->refreshReleaseOrderProductsTable();
                Notification::make()
                    ->title('Status bijgewerkt.')
                    ->success()
                    ->send();

                return;
            }

            $this->confirmOrderProductRecord = null;
            $this->record->setStatus(ReleaseOrderStatus::Delivered);
            $this->record->save();
            $this->releaseOrderStatus = ReleaseOrderStatus::Delivered->value;
            $this->dispatch('open-modal', id: 'release_order_delivered_confirm');

            return;
        }

        $this->record->setStatus($newStatus);
        $this->record->save();

        $this->record->refresh();
        $this->releaseOrderStatus = $this->record->getStatus()?->value;

        Notification::make()
            ->title('Status bijgewerkt.')
            ->success()
            ->send();
    }

    public function syncReleaseOrderStatusFromRecord(): void
    {
        $this->record->refresh();
        $this->releaseOrderStatus = $this->record->getStatus()?->value;
        $this->refreshReleaseOrderProductsTable();
    }

    protected function refreshReleaseOrderProductsTable(): void
    {
        $this->flushCachedTableRecords();
        $this->dispatch('$refresh');
    }

    public function getBackToUrl(): string
    {
        $orderId = $this->returnToOrderId ?? $this->record?->main_id;
        if ($orderId !== null) {
            return route('filament.app.resources.mains.view', ['record' => $orderId]) . '?tab=purchase';
        }

        return ReleaseOrderResource::getUrl('index');
    }

    public function getBackToTitle(): string
    {
        $orderId = $this->returnToOrderId ?? $this->record?->main_id;

        return $orderId !== null ? 'Aanvraag' : 'Afroep-overzicht';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Timeline for Afroepstatus overview (same structure as purchase order status overview).
     *
     * @return Collection<int, array{status: ReleaseOrderStatus, label: string, category: string, date: \Carbon\Carbon|null}>
     */
    public function getReleaseOrderStatusTimeline(): Collection
    {
        $record = $this->record;
        $current = $record?->getStatus();
        $sentAt = $record?->sent_at;

        $items = array_values(ReleaseOrderStatus::allWithCategoriesForSelect());

        return collect($items)->map(function (array $item) use ($current, $sentAt): array {
            $item['date'] = null;
            if (($item['status'] ?? null) === $current && $sentAt !== null && $current !== null && $current !== ReleaseOrderStatus::Initial) {
                $item['date'] = $sentAt;
            }

            return $item;
        });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->record->orderProducts()->getQuery())
            ->columns([
                TextColumn::make('product.uid')
                    ->label('Artikelcode')
                    ->sortable(),
                TextColumn::make('value')
                    ->label('Artikelnaam')
                    ->limit(50),
                TextColumn::make('qty')
                    ->label('Aantal')
                    ->sortable(),
                SelectColumn::make('status')
                    ->label('Status')
                    ->options(OrderProductStatus::getReleaseOrderLineStatusLabels())
                    ->inline()
                    ->selectablePlaceholder(false)
                    ->disableOptionWhen(function (string $value, OrderProduct $record): bool {
                        $current = $record->getStatus();
                        if ($current === null) {
                            return true;
                        }

                        if ($value === $current->value) {
                            return false;
                        }

                        $next = $this->nextReleaseOrderProductStatus($current);

                        return $next === null || $value !== $next->value;
                    })
                    ->disabled(fn (OrderProduct $record): bool => $record->getStatus() === OrderProductStatus::PickedReceived)
                    ->tooltip(function (SelectColumn $column): ?string {
                        $record = $column->getRecord();
                        if (! $record instanceof OrderProduct) {
                            return null;
                        }

                        return $record->getStatus() === OrderProductStatus::PickedReceived
                            ? 'Status gepickt kan niet worden gewijzigd.'
                            : null;
                    })
                    ->updateStateUsing(function (OrderProduct $record, OrderProductStatus|string $state): void {
                        if ($state === '' || $state === null) {
                            return;
                        }

                        if ($record->getStatus() === OrderProductStatus::PickedReceived) {
                            return;
                        }

                        $status = $state instanceof OrderProductStatus ? $state : OrderProductStatus::tryFrom((string) $state);
                        if ($status === null) {
                            return;
                        }
                        if (! $this->canMoveToNextOrderProductStatus($record->getStatus(), $status)) {
                            return;
                        }

                        $areOthersDelivered = $this->getTableRecords()->except($record->id)->every('status', '=', OrderProductStatus::Delivered);
                        if ($areOthersDelivered && $status === OrderProductStatus::Delivered) {
                            $record->setStatus(OrderProductStatus::Delivered);
                            $record->save();
                            $this->confirmOrderProductRecord = $record;
                            $this->dispatch('open-modal', id: 'release_order_delivered_confirm');

                            return;
                        }

                        $record->setStatus($status);
                        $record->save();
                        $this->syncReleaseOrderStatusFromRecord();
                    }),
            ])
            ->paginated([10, 25, 50, 100, 250, 500])
            ->defaultPaginationPageOption(500)
            ->striped()
            ->extraAttributes(['class' => 'orderProductsTable']);
    }

    #[On('confirmReleaseOrderDelivered')]
    public function confirmReleaseOrderDeliveredFromModal(bool $confirm): void
    {
        $modalId = 'release_order_delivered_confirm';

        if (! $confirm) {
            $this->confirmOrderProductRecord = null;
            $this->dispatch('close-modal', id: $modalId);
            $this->syncReleaseOrderStatusFromRecord();

            return;
        }

        $this->record->applyReleaseDeliveredModalConfirm($this->confirmOrderProductRecord);

        Notification::make()
            ->title('Geleverde regels zijn op Gepickt (ingekocht) gezet.')
            ->success()
            ->send();

        $this->confirmOrderProductRecord = null;
        $this->dispatch('close-modal', id: $modalId);
        $this->syncReleaseOrderStatusFromRecord();
    }

    protected function canMoveToNextReleaseOrderStatus(?ReleaseOrderStatus $from, ReleaseOrderStatus $to): bool
    {
        return $this->nextReleaseOrderStatus($from) === $to;
    }

    protected function canMoveToNextOrderProductStatus(?OrderProductStatus $from, OrderProductStatus $to): bool
    {
        if ($from === null) {
            return true;
        }

        $next = $this->nextReleaseOrderProductStatus($from);

        return $next !== null && $next === $to;
    }

    protected function nextReleaseOrderStatus(?ReleaseOrderStatus $from): ?ReleaseOrderStatus
    {
        if ($from === null || $from === ReleaseOrderStatus::Initial || $from === ReleaseOrderStatus::PartiallyConfirmed) {
            return ReleaseOrderStatus::Confirmed;
        }

        if ($from === ReleaseOrderStatus::Purchased) {
            return ReleaseOrderStatus::Confirmed;
        }

        if ($from === ReleaseOrderStatus::Confirmed || $from === ReleaseOrderStatus::PartiallyDelivered) {
            return ReleaseOrderStatus::Delivered;
        }

        return null;
    }

    protected function nextReleaseOrderProductStatus(OrderProductStatus $from): ?OrderProductStatus
    {
        return match ($from) {
            OrderProductStatus::Sent => OrderProductStatus::Confirmed,
            OrderProductStatus::Confirmed => OrderProductStatus::Delivered,
            OrderProductStatus::Delivered => OrderProductStatus::PickedReceived,
            default => null,
        };
    }
}
