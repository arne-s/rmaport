<?php

namespace App\Filament\Resources\ReleaseOrders\Schemas;

use App\Enums\CustomerType;
use App\Enums\ReleaseOrderStatus;
use App\Models\Customer;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ReleaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('reference_number')
                    ->required(),
                Select::make('order_id')
                    ->relationship('order', 'id')
                    ->default(null),
                Select::make('main_id')
                    ->relationship('main', 'id')
                    ->default(null),
                TextInput::make('quote_id')
                    ->numeric()
                    ->default(null),
                Select::make('dealer_id')
                    ->relationship(
                        'dealer',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query
                            ->where('type', CustomerType::Dealer->value)
                            ->orderBy('name'),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Customer $record): string => $record->getName() ?? $record->getDescriptor())
                    ->required(),
                Select::make('status')
                    ->options(ReleaseOrderStatus::visibleStatuses())
                    ->disableOptionWhen(function (string $value, Get $get): bool {
                        $current = (string) ($get('status') ?? '');
                        $map = ReleaseOrderStatus::selectableStatuses();

                        return (! ($map[$value] ?? true)) && $value !== $current;
                    })
                    ->default('initial')
                    ->required(),
                DateTimePicker::make('sent_at'),
                Toggle::make('is_cancelled'),
            ]);
    }
}
