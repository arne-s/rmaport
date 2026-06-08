<?php

namespace App\Filament\Resources\PurchaseOrderInvoiceResource\Actions;

use App\Actions\LinkPurchaseOrderInvoiceAction;
use App\Enums\PurchaseOrderStatus;
use App\Models\Order\StockOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderInvoice;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

enum LinkableOrderType: string
{
    case PurchaseOrder = 'purchase_order';
    case StockOrder = 'stock_order';
}

class LinkPurchaseOrderAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->name('linkPurchaseOrder')
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->modalIcon('heroicon-o-link')
            ->modalHeading('Inkooporder koppelen')
            ->modalDescription('Koppel deze inkoopfactuur aan een inkooporder of voorraadorder.')
            ->modalWidth(Width::Medium)
            ->centerModal()
            ->modalFooterActionsAlignment(Alignment::Between)
            ->extraModalWindowAttributes(['class' => 'modalForm'])
            ->modalSubmitActionLabel('Koppelen')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']))
            ->fillForm(fn (): array => $this->defaultLinkPurchaseOrderFormState())
            ->schema([
                Select::make('order_type')
                    ->label('Type')
                    ->options([
                        LinkableOrderType::PurchaseOrder->value => 'Inkooporder',
                        LinkableOrderType::StockOrder->value => 'Voorraadorder',
                    ])
                    ->default(LinkableOrderType::PurchaseOrder->value)
                    ->required()
                    ->live(),

                Select::make('purchase_order_id')
                    ->label('Inkooporder')
                    ->visible(fn (Get $get): bool => $get('order_type') === LinkableOrderType::PurchaseOrder->value)
                    ->required(fn (Get $get): bool => $get('order_type') === LinkableOrderType::PurchaseOrder->value)
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => $this->suggestedPurchaseOrderOptions($this->resolveLinkingInvoice()))
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchPurchaseOrders(
                        $search,
                        $this->resolveLinkingInvoice(),
                    ))
                    ->getOptionLabelUsing(fn (string $value): ?string => $this->formatPurchaseOrderLabel(
                        PurchaseOrder::query()->linkable()->with('supplier')->find($value),
                    )),

                Select::make('stock_order_id')
                    ->label('Voorraadorder')
                    ->visible(fn (Get $get): bool => $get('order_type') === LinkableOrderType::StockOrder->value)
                    ->required(fn (Get $get): bool => $get('order_type') === LinkableOrderType::StockOrder->value)
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => $this->suggestedStockOrderOptions($this->resolveLinkingInvoice()))
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchStockOrders(
                        $search,
                        $this->resolveLinkingInvoice(),
                    ))
                    ->getOptionLabelUsing(fn (string $value): ?string => $this->formatStockOrderLabel(
                        StockOrder::query()->with('supplier')->find($value),
                    )),
            ])
            ->action(function (array $data, LinkPurchaseOrderInvoiceAction $linkAction): void {
                $livewire = $this->getLivewire();

                if (! property_exists($livewire, 'linkingPurchaseOrderInvoiceId') || $livewire->linkingPurchaseOrderInvoiceId === null) {
                    throw ValidationException::withMessages([
                        'purchase_order_id' => 'Geen inkoopfactuur geselecteerd om te koppelen.',
                    ]);
                }

                $record = PurchaseOrderInvoice::query()->find($livewire->linkingPurchaseOrderInvoiceId);

                if ($record === null) {
                    throw ValidationException::withMessages([
                        'purchase_order_id' => 'Inkoopfactuur niet gevonden.',
                    ]);
                }

                $purchaseOrder = $this->resolvePurchaseOrder($data);

                if ($purchaseOrder === null) {
                    throw ValidationException::withMessages([
                        'purchase_order_id' => 'Geen inkooporder gevonden voor de geselecteerde voorraadorder.',
                    ]);
                }

                $linkAction->execute($record, $purchaseOrder);

                $livewire->linkingPurchaseOrderInvoiceId = null;

                Notification::make()
                    ->title('Inkoopfactuur gekoppeld')
                    ->body("Gekoppeld aan {$purchaseOrder->reference_number}.")
                    ->success()
                    ->send();
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolvePurchaseOrder(array $data): ?PurchaseOrder
    {
        if (($data['order_type'] ?? null) === LinkableOrderType::StockOrder->value) {
            $stockOrder = StockOrder::query()->find($data['stock_order_id'] ?? null);

            if ($stockOrder === null) {
                return null;
            }

            return PurchaseOrder::query()
                ->linkable()
                ->where('order_id', $stockOrder->getId())
                ->first();
        }

        return PurchaseOrder::query()->linkable()->find($data['purchase_order_id'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultLinkPurchaseOrderFormState(): array
    {
        $invoice = $this->resolveLinkingInvoice();
        $purchaseOptions = $this->suggestedPurchaseOrderOptions($invoice);
        $stockOptions = $this->suggestedStockOrderOptions($invoice);

        $state = [
            'order_type' => LinkableOrderType::PurchaseOrder->value,
            'purchase_order_id' => null,
            'stock_order_id' => null,
        ];

        if (count($purchaseOptions) === 1) {
            $state['purchase_order_id'] = (string) array_key_first($purchaseOptions);
        }

        if (count($stockOptions) === 1) {
            $state['stock_order_id'] = (string) array_key_first($stockOptions);
        }

        return $state;
    }

    private function resolveLinkingInvoice(): ?PurchaseOrderInvoice
    {
        $livewire = $this->getLivewire();

        if (! property_exists($livewire, 'linkingPurchaseOrderInvoiceId')
            || $livewire->linkingPurchaseOrderInvoiceId === null) {
            return null;
        }

        return PurchaseOrderInvoice::query()->find($livewire->linkingPurchaseOrderInvoiceId);
    }

    /**
     * @return array<string, string>
     */
    private function suggestedPurchaseOrderOptions(?PurchaseOrderInvoice $invoice): array
    {
        if ($invoice?->main_id !== null) {
            $mainScoped = $this->mapPurchaseOrdersToOptions(
                $this->purchaseOrdersQueryForLinking($invoice)
                    ->where('main_id', $invoice->main_id)
                    ->limit(50)
                    ->get(),
            );

            if ($mainScoped !== []) {
                return $mainScoped;
            }
        }

        return $this->searchPurchaseOrders('', $invoice);
    }

    /**
     * @return array<string, string>
     */
    private function suggestedStockOrderOptions(?PurchaseOrderInvoice $invoice): array
    {
        return $this->searchStockOrders('', $invoice);
    }

    /**
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    private function purchaseOrdersQueryForLinking(?PurchaseOrderInvoice $invoice): Builder
    {
        $query = PurchaseOrder::query()
            ->linkable()
            ->with('supplier');

        if ($invoice?->main_id !== null) {
            $query->orderByRaw('CASE WHEN main_id = ? THEN 0 ELSE 1 END', [$invoice->main_id]);
        }

        if (filled($invoice?->supplier_name)) {
            $supplierName = $invoice->supplier_name;
            $query->orderByRaw(
                'CASE WHEN EXISTS (
                    SELECT 1 FROM suppliers
                    WHERE suppliers.id = purchase_orders.supplier_id
                    AND suppliers.name LIKE ?
                ) THEN 0 ELSE 1 END',
                ['%' . $supplierName . '%'],
            );
        }

        return $query->orderByDesc('created_at');
    }

    /**
     * @return array<string, string>
     */
    private function searchPurchaseOrders(string $search, ?PurchaseOrderInvoice $invoice = null): array
    {
        $search = trim($search);

        $query = $this->purchaseOrdersQueryForLinking($invoice)->limit(50);

        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('reference_number', 'like', '%' . $search . '%')
                    ->orWhereHas('supplier', fn ($supplierQuery) => $supplierQuery->where('name', 'like', '%' . $search . '%'));
            });
        }

        return $this->mapPurchaseOrdersToOptions($query->get());
    }

    /**
     * @return array<string, string>
     */
    private function searchStockOrders(string $search, ?PurchaseOrderInvoice $invoice = null): array
    {
        $search = trim($search);

        $query = StockOrder::query()
            ->with('supplier')
            ->where('status', '!=', PurchaseOrderStatus::Initial->value)
            ->whereHas('purchaseOrders', fn (Builder $purchaseOrderQuery): Builder => $purchaseOrderQuery->linkable())
            ->orderByDesc('updated_at')
            ->limit(50);

        if (filled($invoice?->supplier_name)) {
            $supplierName = $invoice->supplier_name;
            $query->orderByRaw(
                'CASE WHEN EXISTS (
                    SELECT 1 FROM suppliers
                    WHERE suppliers.id = orders.supplier_id
                    AND suppliers.name LIKE ?
                ) THEN 0 ELSE 1 END',
                ['%' . $supplierName . '%'],
            );
        }

        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('uid', 'like', '%' . $search . '%')
                    ->orWhereHas('supplier', fn ($supplierQuery) => $supplierQuery->where('name', 'like', '%' . $search . '%'));
            });
        }

        return $this->mapStockOrdersToOptions($query->get());
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PurchaseOrder>  $purchaseOrders
     * @return array<string, string>
     */
    private function mapPurchaseOrdersToOptions($purchaseOrders): array
    {
        return $purchaseOrders
            ->mapWithKeys(fn (PurchaseOrder $purchaseOrder): array => [
                (string) $purchaseOrder->getKey() => $this->formatPurchaseOrderLabel($purchaseOrder) ?? '',
            ])
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, StockOrder>  $stockOrders
     * @return array<string, string>
     */
    private function mapStockOrdersToOptions($stockOrders): array
    {
        return $stockOrders
            ->mapWithKeys(fn (StockOrder $stockOrder): array => [
                (string) $stockOrder->getKey() => $this->formatStockOrderLabel($stockOrder) ?? '',
            ])
            ->all();
    }

    private function formatPurchaseOrderLabel(?PurchaseOrder $purchaseOrder): ?string
    {
        if ($purchaseOrder === null) {
            return null;
        }

        $supplierName = $purchaseOrder->supplier?->name;

        return filled($supplierName)
            ? "{$purchaseOrder->reference_number} · {$supplierName}"
            : $purchaseOrder->reference_number;
    }

    private function formatStockOrderLabel(?StockOrder $stockOrder): ?string
    {
        if ($stockOrder === null) {
            return null;
        }

        $uid = $stockOrder->getUidFormatted() ?: $stockOrder->uid;
        $supplierName = $stockOrder->supplier?->name;

        return filled($supplierName)
            ? "{$uid} · {$supplierName}"
            : (string) $uid;
    }
}
