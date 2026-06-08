<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseOrderType;
use App\Enums\ReleaseOrderStatus;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\PurchaseOrderResource\Pages\Concerns\CombinedPurchaseOrderListRecords;
use App\Filament\Resources\PurchaseOrderResource\Pages\Concerns\HasPurchaseProcessPageBodyClass;
use App\Filament\Resources\ReleaseOrders\ReleaseOrderResource;
use Carbon\Carbon;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class ListPurchaseOrdersConfirmed extends ListPurchaseOrders
{
    use CombinedPurchaseOrderListRecords;
    use HasPurchaseProcessPageBodyClass;

    protected static string $resource = PurchaseOrderResource::class;

    public ?string $status = 'confirmed';

    protected function makeTable(): Table
    {
        return $this->makeCombinedPurchaseReleaseTable();
    }

    protected function combinedListStatusValues(): array
    {
        return [PurchaseOrderStatus::Confirmed->value];
    }

    public function getBreadcrumbs(): array
    {
        return array_merge(parent::getSubpageBreadcrumbs(), ['Bevestigd']);
    }

    protected function getHeaderActions(): array
    {
        return parent::getPageTabs();
    }

    public function table(Table $table): Table
    {
        $poStatuses = PurchaseOrderStatus::visibleStatuses();
        $roStatuses = ReleaseOrderStatus::visibleStatuses();
        $allowedNext = [
            PurchaseOrderStatus::PartiallyDelivered->value,
            PurchaseOrderStatus::Delivered->value,
            ReleaseOrderStatus::PartiallyDelivered->value,
            ReleaseOrderStatus::Delivered->value,
        ];

        return $table
            ->records(fn(?string $sortColumn, ?string $sortDirection): Collection => $this->buildCombinedListRecords($sortColumn, $sortDirection))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Datum ingekocht')
                    ->sortable()
                    ->formatStateUsing(function ($state, array $record): HtmlString {
                        $dt = $record['created_at'];
                        if (! $dt instanceof Carbon) {
                            return new HtmlString('');
                        }

                        return new HtmlString('<div class="numberPlusDate noBorder">' . $dt->translatedFormat('d-m-Y') . '</div>');
                    }),

                TextColumn::make('document_ref')
                    ->label('Document #')
                    ->state(true)
                    ->html()
                    ->formatStateUsing(function ($state, array $record): HtmlString {
                        if ($record['kind'] === 'po') {
                            $po = $record['purchaseOrder'];
                            if ($po->reference_number === null || $po->reference_number === '') {
                                return new HtmlString('<span class="text-gray-400 text-sm"><em>In behandeling...</em></span>');
                            }

                            return new HtmlString(
                                '<div class="linksDocuments"><a class="openDocument main-request-number-link" href="'
                                    . route('filament.app.resources.purchase-orders.view', ['record' => $po->getId()])
                                    . '">' . e($po->reference_number) . '</a></div>'
                            );
                        }
                        if ($record['kind'] === 'so') {
                            $so = $record['stockOrder'];
                            return new HtmlString(
                                '<div class="linksDocuments"><a class="openDocument main-request-number-link" href="'
                                    . route('filament.app.resources.stock-orders.view', ['record' => $so->getId()])
                                    . '">' . e($so->getUidFormatted() ?: 'In behandeling...') . '</a></div>'
                            );
                        }
                        $ro = $record['releaseOrder'];

                        return new HtmlString(
                            '<div class="linksDocuments"><a class="openDocument main-request-number-link" href="'
                                . ReleaseOrderResource::getUrl('view', ['record' => $ro->getId()])
                                . '">' . e($ro->getReferenceNumber()) . '</a></div>'
                        );
                    }),

                TextColumn::make('latestExpectedDeliveryDate')
                    ->label('Verwachte levering')
                    ->state(true)
                    ->formatStateUsing(function ($state, array $record): string {
                        if ($record['kind'] !== 'po') {
                            return '';
                        }
                        $po = $record['purchaseOrder'];
                        $date = $po->latestExpectedDeliveryDate;
                        if ($date === null) {
                            return '';
                        }

                        return $date->isoFormat('\W\e\e\k W, Y');
                    })
                    ->extraAttributes(function (array $record): array {
                        if ($record['kind'] !== 'po') {
                            return [];
                        }
                        $po = $record['purchaseOrder'];
                        $date = $po->latestExpectedDeliveryDate;

                        return $po->getStatus() !== PurchaseOrderStatus::Delivered && $date && ($date->isPast() || $date->isCurrentWeek())
                            ? ['style' => 'color: red; font-weight: 700;']
                            : [];
                    }),

                TextColumn::make('type')
                    ->label('Type')
                    ->sortable()
                    ->state(true)
                    ->formatStateUsing(function ($state, array $record): string {
                        if ($record['kind'] === 'ro') {
                            return 'Afroep';
                        }
                        if ($record['kind'] === 'so') {
                            return 'Voorraad inkoop';
                        }

                        return $record['purchaseOrder']->getType()->getLabel();
                    }),

                SelectColumn::make('status')
                    ->options(function (array $record) use ($poStatuses, $roStatuses): array {
                        if ($record['kind'] === 'ro') {
                            return $roStatuses;
                        }

                        return $poStatuses;
                    })
                    ->selectablePlaceholder(false)
                    ->disabled()
                    ->disableOptionWhen(fn(string $value): bool => ! in_array($value, $allowedNext, true))
                    ->updateStateUsing(function ($state, array $record): void {
                        if ($record['kind'] === 'po') {
                            $this->handleStatusChange($record['purchaseOrder'], (string) $state);
                        } elseif ($record['kind'] === 'ro') {
                            $this->handleReleaseOrderStatusChange($record['releaseOrder'], (string) $state);
                        }
                        $this->flushCachedTableRecords();
                        $this->dispatch('$refresh');
                    }),

                TextColumn::make('confirmation_pdf')
                    ->label('Bevestiging (PDF)')
                    ->state(true)
                    ->html()
                    ->formatStateUsing(function ($state, array $record): HtmlString {
                        if ($record['kind'] === 'ro') {
                            return new HtmlString('<span class="text-gray-400 text-sm">—</span>');
                        }
                        if ($record['kind'] === 'so') {
                            return new HtmlString('<span class="text-gray-400 text-sm">—</span>');
                        }

                        return new HtmlString(view('filament.tables.columns.purchase-order-confirmation-embedded', [
                            'record' => $record['purchaseOrder'],
                            'displayRefNumber' => false,
                            'displayDate' => false,
                            'showMultiple' => true,
                        ])->render());
                    }),

                TextColumn::make('aanvraagnummer')
                    ->label('Aanvraagnummer')
                    ->state(true)
                    ->html()
                    ->formatStateUsing(function ($state, array $record): HtmlString {
                        if ($record['kind'] === 'so') {
                            return new HtmlString('N.v.t.');
                        }

                        $main = $record['kind'] === 'ro'
                            ? $record['releaseOrder']->main
                            : ($record['purchaseOrder']->main ?? $record['purchaseOrder']->order?->main);

                        if ($main === null || $main->uid === null || $main->uid === '') {
                            return new HtmlString('<span class="text-gray-400 text-sm"><em>In behandeling...</em></span>');
                        }

                        return new HtmlString(
                            '<div class="linksDocuments"><a class="openDocument main-request-number-link" href="'
                                . route('filament.app.resources.mains.view', ['record' => $main->getId()])
                                . '">' . e($main->uid) . '</a></div>'
                        );
                    }),

                TextColumn::make('counterparty')
                    ->label('Leverancier / Dealer')
                    ->sortable()
                    ->state(true)
                    ->formatStateUsing(function ($state, array $record): string {
                        if ($record['kind'] === 'po') {
                            return (string) ($record['purchaseOrder']->supplier?->name ?? '');
                        }
                        if ($record['kind'] === 'so') {
                            return (string) ($record['stockOrder']->supplier?->name ?? '');
                        }

                        return (string) ($record['releaseOrder']->dealer?->getName() ?? '');
                    }),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return parent::content($schema)
            ->components([
                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Dashboard',
                        'url' => route('filament.app.pages.dashboard'),
                        'class' => 'breadcrumb-mob-purchase-order',
                    ]),
                ...$schema->getComponents(),
                view('livewire.modals.mts-purchase-order-delivered-confirm-modal'),
                view('livewire.modals.mto-purchase-order-delivered-confirm-modal'),
                view('livewire.modals.release-order-delivered-confirm-modal'),
            ]);
    }
}
