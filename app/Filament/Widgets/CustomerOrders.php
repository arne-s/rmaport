<?php

namespace App\Filament\Widgets;

use App\Enums\OrderGeneralStatus;
use App\Filament\Resources\Resource;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use App\Filament\Tables\Actions\OrderExportAction;
use App\Filament\Tables\Columns\Portal\OrderStatusColumn;
use App\Models\Customer;
use App\Models\Order\BaseOrder;
use Filament\Tables;
use Filament\Tables\Actions\Modal\Actions\Action;
use Filament\Tables\Filters\Layout;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CustomerOrders extends BaseWidget
{
    public static ?string $heading = 'Klantbestellingen';

    public Customer $customer;
    public ?Model $record = null;
    public function mount(?Customer $customer = null)
    {
//        die('deprecated');
        $this->customer = $customer;
    }

    protected function getTableQuery(): Builder
    {
        return BaseOrder::query()
            // ->whereHas('customer', function ($q) {
            //     return $q->where('email', $this->customer->email);
            // })
            ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value]);
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('uid')->label('Nr')->sortable(),
            \App\Filament\Tables\Columns\OrderStatusColumn::make('type_translated')
                ->label('Type'),

            TextColumn::make('company.name')
                ->label('Dealer')
                ->sortable(),

            OrderStatusColumn::make('status')
                ->label('Status')
                ->sortable(),

            TextColumn::make('sent_at')
                ->label('Datum')
                ->date('j M Y (H:i)')
                ->sortable(),
        ];
    }

    public function getTableFilters(): array
    {
        return [Resource::getTypeFilter()];
    }

    public function getTableFiltersLayout(): ?string
    {
        return FiltersLayout::AboveContent;
    }

    protected function getTableActions(): array
    {
        return [
            //OrderExportAction::make('export')
             //   ->modalHeading('Orderbevestiging bekijken'),
            EditAction::make('edit')
                ->label('Bekijken')
                ->modalHeading('Orderdetails')
                ->modalSubmitActionLabel(__('filament-support::actions/edit.single.modal.actions.save.label'))
                ->requiresConfirmation(true)
                ->icon('heroicon-s-eye')
                ->extraAttributes([
                    'class' => 'button-primary',
                ]),
        ];
    }



}
