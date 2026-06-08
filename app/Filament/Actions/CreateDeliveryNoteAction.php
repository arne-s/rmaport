<?php

namespace App\Filament\Actions;

use App\Actions\SendOrderConfirmationFromModalDataAction;
use App\Actions\SyncDeliveryNotePdfAction;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderSubtype;
use App\Enums\OrderType;
use App\Filament\Resources\OrderResource\Actions\ApproveOrderEmailAction;
use App\Models\Document;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Services\InventoryService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateDeliveryNoteAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'create_delivery_note';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Pakbon')
            ->icon('heroicon-o-document-text')
            ->modalHeading('Pakbon')
            ->closeModalByEscaping(false)
            ->schema(ApproveOrderEmailAction::makeOrderConfirmationEmailModalSchema(
                function ($livewire): ?Order {
                    $record = $livewire->record ?? null;
                    if (! $record instanceof Main) {
                        return null;
                    }

                    return self::resolveOrderForDeliveryNote($record);
                },
            ))
            ->action(function (array $data, $livewire): void {
                $record = $livewire->record ?? null;
                if (! $record instanceof Main) {
                    return;
                }

                $order = self::resolveOrderForDeliveryNote($record);
                if (! $order instanceof Order) {
                    Notification::make()
                        ->title('Pakbon')
                        ->body('Er is nog geen order met documentnummer gekoppeld. Sla de order eerst op.')
                        ->warning()
                        ->send();

                    return;
                }

                $order->setAuthorId(Auth::id());
                $order->saveQuietly();

                try {
                    $data = ApproveOrderEmailAction::finalizeModalDataForOrder($order, $livewire, $data);
                } catch (ValidationException $e) {
                    $message = collect($e->errors())->flatten()->first() ?? $e->getMessage();
                    Notification::make()
                        ->title('Pakbon')
                        ->body($message)
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    app(SendOrderConfirmationFromModalDataAction::class)->execute($order, $data);

                    $order->main?->orderEvents()->create([
                        'type' => 'Orderbevestiging verzonden (pakbon): ' . $order->getUidFormatted(),
                        'data' => [],
                        'user_id' => Auth::id(),
                    ]);

                    app(SyncDeliveryNotePdfAction::class)->execute($order);

                    $order->refresh();
                    $record->refresh();

                    if ($record->getSubtype() === OrderSubtype::Part) {
                        self::finalizePartMainAfterPakbonSuccess($record, $order);
                    }

                    $livewire->dispatch('delivery-note-saved');

                    Notification::make()
                        ->title('Pakbon')
                        ->body('De orderbevestiging is verzonden en de pakbon is bijgewerkt.')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Log::error('Pakbon / orderbevestiging mislukt', ['exception' => $e]);
                    Notification::make()
                        ->title('Pakbon')
                        ->body('Verzenden of genereren is mislukt. Probeer het opnieuw.')
                        ->danger()
                        ->send();
                }
            })
            ->modalSubmitActionLabel('Versturen')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    /**
     * Resolve the order used for {@see \App\Actions\SyncDeliveryNotePdfAction} (same rules as the purchase/order tabs).
     */
    public static function resolveOrderForDeliveryNote(Main $main): ?Order
    {
        return $main->resolveOrderForDeliveryNote();
    }

    /**
     * After a successful pakbon for Part: align order status with “verzonden”, mirror {@see \App\Filament\Resources\OrderResource\Pages\EditOrder::placeOrder} side-effects where safe, then slot invoice.
     */
    private static function finalizePartMainAfterPakbonSuccess(Main $main, Order $order): void
    {
        if (! in_array($order->getStatus(), [
            OrderGeneralStatus::Initial,
            OrderGeneralStatus::Draft,
            OrderGeneralStatus::Pending,
        ], true)) {
            if ($main->getInvoiceId() === null) {
                try {
                    $main->createInvoiceIfRequired();
                } catch (Throwable $e) {
                    Log::error('createInvoiceIfRequired na pakbon (onderdeel) mislukt', [
                        'main_id' => $main->getKey(),
                        'exception' => $e,
                    ]);
                }
            }

            self::alignPartSalesOrderDocumentStatusAfterPakbonInvoice($order);

            return;
        }

        $order->setStatus(OrderGeneralStatus::Sent);
        $order->saveQuietly();

        $main->updateQuietly(['order_id' => $order->getId()]);

        if ($order->getUid()) {
            Order::withoutGlobalScopes()
                ->where('type', OrderType::Order->value)
                ->where('uid', $order->getUid())
                ->whereNot('id', $order->getId())
                ->update(['status' => OrderGeneralStatus::Changed->value]);
        }

        try {
            app(InventoryService::class)->reserveForOrder($order);
        } catch (Throwable $e) {
            Log::error('Voorraad reserveren na pakbon (onderdeel) mislukt', [
                'order_id' => $order->getKey(),
                'exception' => $e,
            ]);
        }

        try {
            Document::createFromOrder($order);
        } catch (Throwable $e) {
            Log::warning('Document na pakbon (onderdeel) niet aangemaakt of dubbel', [
                'order_id' => $order->getKey(),
                'exception' => $e,
            ]);
        }

        if ($main->getInvoiceId() === null) {
            try {
                $main->refresh();
                $main->createInvoiceIfRequired();
            } catch (Throwable $e) {
                Log::error('createInvoiceIfRequired na pakbon (onderdeel) mislukt', [
                    'main_id' => $main->getKey(),
                    'exception' => $e,
                ]);
            }
        }

        self::alignPartSalesOrderDocumentStatusAfterPakbonInvoice($order);
    }

    /**
     * {@see Order::createInvoice()} sets the sales order row to {@see OrderGeneralStatus::Completed} (“Akkoord”);
     * after pakbon we want {@see OrderGeneralStatus::Sent} (“Verzonden”) on that order in Filament.
     */
    private static function alignPartSalesOrderDocumentStatusAfterPakbonInvoice(Order $order): void
    {
        $order->refresh();
        if ($order->getStatus() !== OrderGeneralStatus::Completed) {
            return;
        }

        $order->setStatus(OrderGeneralStatus::Sent);
        $order->saveQuietly();
    }
}
