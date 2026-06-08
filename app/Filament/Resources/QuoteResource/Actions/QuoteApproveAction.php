<?php

namespace App\Filament\Resources\QuoteResource\Actions;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderType;
use App\Exceptions\OrderOutOfStockException;
use App\Models\Order\BaseOrder;
use App\Models\Order\Order;
use App\Models\Order\Quote;
use Filament\Actions\Action;
use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**  @method Quote|Order|BaseOrder getRecord() */
class QuoteApproveAction extends Action
{
    use CanCustomizeProcess;

    public static function getDefaultName(): ?string
    {
        return 'quote_approve';
    }

    public function getModalDescription(): string|Htmlable|null
    {
        return 'De status zal wijzigen naar "Order: Concept". De afdeling administratie wordt geïnformeerd.';
    }


    public function getModalSubmitActionLabel(): string
    {
        return 'Akkoord';
    }


    protected function setUp(): void
    {
        parent::setUp();

        // Default: only for pending quotes; widget can override with ->visible()
        $this->visible(fn (Quote|Order|BaseOrder $record): bool => $this->isQuoteRecord($record)
            && in_array($this->getStatusValue($record), [OrderGeneralStatus::Pending->value, OrderGeneralStatus::Sent->value], true));

        $this->button()
            ->label('')
            ->extraAttributes(['class' => 'approveAction']);

        $this->modalHeading('Statuswijziging: offerte akkoord');

        $this->action(function (): void {
            try {
                $this->process(function (array $data, Quote|Order|BaseOrder $record) {
                    $record = $this->resolveToQuoteOrOrder($record);
                    if ($record === null) {
                        return;
                    }

                    try {
                       $record->acceptQuote();

                        Notification::make()
                            ->title('De offerte is goedgekeurd')
                            ->body('Order #' . $record->getUidFormatted() . ' is aangemaakt.')
                            ->success()
                            ->send();
                    } catch (OrderOutOfStockException $e) {
                        Notification::make()
                            ->title('Artikelen niet op voorraad')
                            ->body('Eén of meer Artikelen in de offerte zijn niet op voorraad en kunnen niet worden besteld.')
                            ->danger()
                            ->send();
                    }
                });
            } catch (Throwable $e) {
                $this->failureNotificationTitle('Geen geldige offerte gevonden');
                $this->failure();
            }
        });

        $this->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    private function getStatusValue(Quote|Order|BaseOrder $record): string
    {
        $status = $record->status ?? null;

        return $status instanceof \BackedEnum ? $status->value : (string) $status;
    }

    private function isQuoteRecord(Quote|Order|BaseOrder $record): bool
    {
        if ($record instanceof Quote) {
            return true;
        }
        if ($record instanceof Order) {
            return false;
        }
        $typeValue = $record->type instanceof \BackedEnum ? $record->type->value : (string) ($record->type ?? '');

        return $typeValue === OrderType::Quote->value;
    }

    private function resolveToQuoteOrOrder(Quote|Order|BaseOrder $record): Quote|Order|null
    {
        if ($record instanceof Quote || $record instanceof Order) {
            return $record;
        }
        $typeValue = $record->type instanceof \BackedEnum ? $record->type->value : (string) ($record->type ?? '');
        if ($typeValue === OrderType::Quote->value) {
            return Quote::withoutGlobalScopes()->find($record->getId()) ?? null;
        }
        if ($typeValue === OrderType::Order->value) {
            return Order::withoutGlobalScopes()->find($record->getId()) ?? null;
        }

        return null;
    }
}
