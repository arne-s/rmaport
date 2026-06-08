<?php

namespace App\Filament\Tables\Actions;

use Filament\Actions\Action;
use App\Models\Order\Order;
use App\Traits\Columns\CanBeEmpty;

/**  @method Order getRecord() */
class OrderMarginsAction extends Action
{
    use CanBeEmpty;

    public static function getDefaultName(): ?string
    {
        return 'order_margins';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->view('filament.tables.columns.download-action', fn ($record) => [
            'label' => 'Marge',
            'modalId' => 'open-order-margins',
            'downloadLink' => fn () => route('documents.orderMarginsDownload', ['orderId' => $record->id]),
        ]);
    }
}
