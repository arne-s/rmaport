<?php

namespace App\Filament\Tables\Actions;

use Filament\Actions\Action;
use App\Models\Order\Order;
use App\Traits\Columns\CanBeEmpty;

/**  @method Order getRecord() */
class PackingSlipAction extends Action
{
    use CanBeEmpty;

    public static function getDefaultName(): ?string
    {
        return 'packing_slip';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->view('filament.tables.columns.download-action', fn ($record) => [
            'label' => 'Pakbon',
            'modalId' => 'open-packing-slip',
            'downloadLink' => fn () => route('documents.packingSlipDownload', ['orderId' => $record->id]),
        ]);
    }
}
