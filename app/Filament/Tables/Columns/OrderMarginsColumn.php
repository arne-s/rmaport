<?php

namespace App\Filament\Tables\Columns;

use App\Traits\Columns\CanBeEmpty;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

class OrderMarginsColumn extends TextColumn
{
    use CanBeEmpty;

    protected function setUp(): void
    {
        $this->view('filament.tables.columns.download-action', function (Model $record): array {
            $orderId = $record->getKey();

            return [
                'label' => 'Marge',
                'modalId' => 'open-order-margins',
                'orderId' => $orderId,
                'downloadLink' => fn (): string => route('documents.orderMarginsDownload', ['orderId' => $orderId]),
            ];
        });
    }

    public function getRecord(): ?Model
    {
        return $this->record;
    }
}
