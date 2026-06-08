<?php

namespace App\Filament\Resources\ReleaseOrders\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ReleaseOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('reference_number'),
                TextEntry::make('order.id')
                    ->label('Order')
                    ->placeholder('-'),
                TextEntry::make('main.id')
                    ->label('Main')
                    ->placeholder('-'),
                TextEntry::make('quote_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('dealer.name')
                    ->label('Dealer'),
                TextEntry::make('status')
                    ->label('Afroepstatus')
                    ->badge(),
                TextEntry::make('sent_at')
                    ->dateTime()
                    ->placeholder('-'),
                IconEntry::make('is_cancelled')
                    ->boolean()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
