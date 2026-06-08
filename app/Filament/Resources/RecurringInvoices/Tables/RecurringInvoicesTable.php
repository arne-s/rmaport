<?php

namespace App\Filament\Resources\RecurringInvoices\Tables;

use App\Enums\RecurringInvoiceFrequency;
use App\Models\RecurringInvoice;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RecurringInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back',
            ]))
            ->headerActions([
                Action::make('recurring-invoice')
                    ->label('Abonnement aanmaken')
                    ->url(route('filament.app.resources.recurring-invoices.create'))
                    ->icon('heroicon-s-plus-circle'),
            ])
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('billing_party')
                    ->label('Klant / dealer')
                    ->state(function (RecurringInvoice $record): string {
                        $record->loadMissing(['billingCustomer']);
                        if ($record->billing_customer_id !== null) {
                            return $record->billingCustomer?->getName() ?? ('Klant #'.$record->billing_customer_id);
                        }

                        return '—';
                    }),
                TextColumn::make('name')
                    ->label('Naam (intern)')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('is_active')
                    ->label('Actief')
                    ->formatStateUsing(fn (mixed $state): string => ((bool) $state) ? 'Ja' : 'Nee'),
                TextColumn::make('frequency')
                    ->label('Frequentie')
                    ->formatStateUsing(fn (?RecurringInvoiceFrequency $state): string => $state instanceof RecurringInvoiceFrequency ? $state->getLabel() : '—'),
                TextColumn::make('next_run_date')
                    ->label('Volgende zending')
                    ->date()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
