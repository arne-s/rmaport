<?php

namespace App\Filament\Resources\StockOrderResource\Pages;

use Filament\Tables\Enums\FiltersLayout;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Resource;
use App\Filament\Resources\StockOrderResource;
use App\Filament\Support\RecordLockNavigation;
use App\Models\User;
use App\Services\RecordLockService;
use App\Enums\PurchaseOrderStatus;
use App\Models\Order\StockOrder;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;


class ListStockOrders extends ListRecords
{
    protected static string $resource = StockOrderResource::class;
    protected static ?string $title = 'Inkooporder concepten';
    protected static ?string $breadcrumb = 'Concepten';

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    protected function getTableQuery(): Builder
    {
        return StockOrder::query()
            ->with(['supplier', 'author'])
            ->where('status', '!=', PurchaseOrderStatus::Initial->value);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Inkooporder-overzicht',
                'url' => route('filament.app.resources.purchase-orders.confirmed'),
                'class' => 'quote-overview-back',
            ]))
            ->extraAttributes(['class' => 'eindklanten-table-icons'])
            ->columns([
                TextColumn::make('updated_at')
                    ->date('j M Y')
                    ->label('Aangepast op')
                    ->sortable(['updated_at']),

                TextColumn::make('supplier.name')
                    ->label('Leverancier')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('uid')
                    ->label('Referentie')
                    ->formatStateUsing(fn (StockOrder $record): string => $record->getUidFormatted() ?: '-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->date('j M Y')
                    ->label('Aangemaakt op')
                    ->sortable(),

                TextColumn::make('author.name')
                    ->label('Gebruiker')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->deferFilters(false)
            ->filters([
                Resource::getDateFilter(),
                Resource::getSupplierFilter('supplier'),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([
                EditAction::make()
                    ->label('')
                    ->before(function (EditAction $action, StockOrder $record): void {
                        $user = Auth::user();
                        if (! $user instanceof User) {
                            return;
                        }

                        $details = app(RecordLockService::class)->getBlockedDetailsFor(
                            $record,
                            $user,
                            StockOrderResource::getUrl('index'),
                        );

                        if ($details !== null) {
                            RecordLockNavigation::notifyDocumentInUse($details);
                            $action->halt();
                        }
                    })
                    ->extraAttributes([
                        'class' => 'button-primary onlyPen',
                    ]),
                DeleteAction::make()
                    ->label('')
                    ->extraAttributes(['style' => 'border: none; padding: 5px !important; color: red !important;'])
                    ->iconButton()->icon('heroicon-o-trash'),
            ])
            ->emptyStateHeading('Geen concepten');
    }
}
