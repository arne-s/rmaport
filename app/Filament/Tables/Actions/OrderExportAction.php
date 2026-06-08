<?php

namespace App\Filament\Tables\Actions;

use Filament\Actions\EditAction;
use Filament\Actions\Action;
use App\Models\Order\BaseOrder;
use Illuminate\Contracts\Support\Htmlable;

// Unused
class OrderExportAction extends EditAction
{

    public function getLabel(): string|Htmlable|null
    {
        /** @var BaseOrder $record */
        $record = $this->record;

        $labels = [
            'quote' => 'Offerte',
            'order' => 'Order',
            'invoice' => 'Factuur',
            'deposit_invoice' => 'Factuur',
            'credit_invoice' => 'Creditfactuur',
        ];

        return $labels[$record->getType()?->value ?? ''] ?? '';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->modalActions([
            Action::make('export')
                ->button()
                ->url(function (): string {
                    // todo: there should be a better way?
                    $id = request()->all()['updates'][0]['payload']['params'][1];
                    return route('order.manager-export', [
                        'order' => $id,
                    ]);
                })
                ->icon('heroicon-s-printer')
                ->label('Opslaan als PDF'),

            Action::make('cancel')
                ->label('Sluiten')
                ->icon('heroicon-m-x-mark')
                ->cancel()
                ->color('gray')
        ]);

        $this->modalHeading(fn () => $this->getLabel() . ' bekijken');

        $this->modalSubmitActionLabel(__('filament-support::actions/edit.single.modal.actions.save.label'));

        $this->icon(false);

        $this->extraAttributes([
            'class' => 'button-primary',
        ]);
    }

}


