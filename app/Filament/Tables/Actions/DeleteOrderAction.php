<?php

namespace App\Filament\Tables\Actions;

use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use App\Models\Order\BaseOrder;
use App\Models\Order\Order;
use Filament\Forms\Components\Checkbox;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Throwable;

class DeleteOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'delete_order';
    }

    public function getModalDescription(): string|Htmlable|null
    {
        $summary = '<div style="text-align: left; margin-top: .5rem">De volgende documenten worden verwijderd:
        <ul style="margin-top: 1rem; margin-bottom: -1rem">';

        $list = $this->itemsToDelete();
        foreach ($list as $item) {
            $summary .= '<li>' . $item->getTypeLabel();
            $summary .= ': <strong>#' . $item->getUidFormatted() . '</strong>';
            $summary .= '</li>';
        }

        $summary .= '</ul></div>';

        return new HtmlString($summary);
    }

    protected function itemsToDelete(): array|Collection
    {
        $record = $this->getRecord();

        if (!$record) {
            return [];
        }

        return BaseOrder::where(function ($query) use ($record) {
            $query->where('id', $record->getId())
                ->orWhere('order_id', $record->getId());
        })
            ->whereNotNull('uid')
            ->orderByRaw('type = "order" DESC')
            ->get();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->iconButton()
            ->icon('heroicon-o-trash')
            ->requiresConfirmation()
            ->modalHeading('Order verwijderen')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']))
            // Add form fields to the modal
            ->mountUsing(fn(Schema $schema) => $schema->fill([
                'has_deposit_invoice' => $this->getRecord()->deposit_invoice_id,
                'has_invoice' => $this->getRecord()->invoice_id,
            ]))
            ->schema([
                Checkbox::make('delete_deposit_invoice_in_exact')
                    ->label('Verwijder ook aanbetalingsfactuur in Exact')
                    ->visible(fn(Get $get) => $get('has_deposit_invoice') && !$this->getRecord()->getIsTest())
                    ->inline()
                    ->default(false),
                Checkbox::make('delete_invoice_in_exact')
                    ->label('Verwijder ook slotfactuur in Exact')
                    ->visible(fn(Get $get) => $get('has_invoice'))
                    ->inline()
                    ->default(false),
            ])
            ->action(function (array $data): void {
                try {
                    /** @var Order[] $list */
                    $list = $this->itemsToDelete();

                    foreach ($list as $item) {
                        // Delete from Exact
                        if (!$this->getRecord()->getIsTest()) {
                            if ($item->type === 'deposit_invoice' && $data['delete_deposit_invoice_in_exact']
                                || $item->type === 'invoice' && $data['delete_invoice_in_exact']) {
                                $success = false;

                                try {
                                    $salesEntry = new \App\Services\Exact\Invoices\ExactSalesEntry(app('exact'));
                                    $success = $salesEntry->deleteSalesEntry($item);
                                } catch (Throwable $e) {
                                    report($e->getMessage());
                                }

                                if (!$success) {
                                    $this->failureNotificationTitle($item->getTypeLabel() . ' ' . $item->getUidFormatted()
                                        . ' kon niet worden verwijderd uit Exact, mogelijk is deze al verwijderd of ingeboekt.');
                                    $this->failure();
                                }
                            }
                        }

                        // Delete associated documents from storage
                        foreach ($item->getSavedDocuments() as $document) {
                            try {
                                if (empty($document)) continue;
                                $document->deleteDocFromStorage();
                            } catch (Throwable $e) {
                                report($e);
                            }
                        }

                        // Delete from database
                        $item->delete();
                    }

                    $this->successNotificationTitle('Order succesvol verwijderd');
                    $this->success();
                } catch (Throwable $e) {
                    $this->failureNotificationTitle('Er is een fout opgetreden bij het verwijderen van de order');
                    $this->failure();
                }
            })
            ->extraAttributes([
                'class' => 'deleteOrderAction',
                'style' => 'border: none; padding: 5px !important; color: red !important;',
            ]);
    }
}
