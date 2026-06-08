<?php

namespace App\Filament\Resources\ReleaseOrders\Tables;

use App\Enums\ReleaseOrderStatus;
use App\Models\Customer;
use App\Models\ReleaseOrder;
use App\Support\NavigationLink;
use Carbon\Carbon;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class ReleaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        $statuses = ReleaseOrderStatus::visibleStatuses();

        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('Type')
                    ->state('Afroep')
                    ->disabledClick(),
                TextColumn::make('created_at')
                    ->label('Datum afgeroepen')
                    ->dateTime('d-m-Y H:i')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => new HtmlString('<div class="numberPlusDate noBorder">' . Carbon::parse($state)->translatedFormat('d-m-Y') . '</div>')),
                TextColumn::make('reference_number')
                    ->label('Afroep #')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state, ReleaseOrder $record) => NavigationLink::releaseOrder(
                        $record->getId(),
                        $state ?? $record->reference_number,
                    )),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn ($state) => $state instanceof ReleaseOrderStatus ? $state->getLabel() : ReleaseOrderStatus::tryFrom((string) $state)?->getLabel() ?? $state)
                    ->sortable(),
                TextColumn::make('order.uid')
                    ->label('Ordernummer')
                    ->searchable(),
                TextColumn::make('dealer_id')
                    ->label('Dealer')
                    ->formatStateUsing(fn ($state, ReleaseOrder $record): string => $record->dealer?->getName() ?? '—'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
