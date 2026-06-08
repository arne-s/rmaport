<?php

namespace App\Filament\Tables\Actions;

use App\Enums\OrderGeneralStatus;
use App\Models\Order\BaseOrder;
use App\Models\Order\Quote;
use Filament\Actions\Action;
use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Throwable;

class CancelQuoteAction extends Action
{
    use CanCustomizeProcess;

    public static function getDefaultName(): ?string
    {
        return 'cancel_quote';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->iconButton()
            ->icon('heroicon-s-x-mark')
            ->requiresConfirmation()
            ->label('Offerte annuleren')
            ->modalHeading('Aanvraag annuleren')
            ->modalDescription(fn (Quote|BaseOrder $record): HtmlString => new HtmlString('<div style="
                margin-top: .5rem;
                font-size: 13px;
                font-weight: 450;
                color: #000;
            ">
                De offerte wordt geannuleerd. De klant en de dealer (indien van toepassing) wordt geïnformeerd.
            </div>'))
            ->extraAttributes([
                'class' => 'deleteOrderAction',
                'style' => '
                    border: none;
                    padding: 0 !important;
                    color: red !important;
                    margin: 0 !important;
                '
            ])
            ->schema([
                TextInput::make('cancel_comment')
                    ->label('Reden van annulering')
                    ->required()
                    ->maxLength(255),
            ])
            ->extraModalWindowAttributes(['class' => 'modalForm'])
            ->action(function (): void {
                try {
                    $this->process(function (array $data, Quote|BaseOrder $record) {
                        $quote = $this->resolveQuote($record);
                        if ($quote === null) {
                            return;
                        }
                        $quote->setStatus(OrderGeneralStatus::Cancelled);
                        $quote->setCancelComment($data['cancel_comment']);
                        $quote->save();

                        $this->successNotificationTitle('Offerte is geannuleerd.');
                        $this->success();

                        $livewire = $this->getLivewire();
                        if ($livewire !== null && method_exists($livewire, 'dispatch')) {
                            $livewire->dispatch('order-docs-updated');
                        }
                    });
                } catch (Throwable $e) {
                    report($e);
                    $this->failureNotificationTitle('Er is een fout opgetreden bij het annuleren van de offerte.');
                    $this->failure();
                }
            })
            ->visible(fn (Quote|BaseOrder $record): bool => in_array($this->resolveQuote($record)?->status, [OrderGeneralStatus::Pending, OrderGeneralStatus::Sent], true))
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    private function resolveQuote(Quote|BaseOrder $record): ?Quote
    {
        if ($record instanceof Quote) {
            return $record;
        }
        $typeValue = $record->type instanceof \BackedEnum ? $record->type->value : (string) ($record->type ?? '');

        return $typeValue === 'quote'
            ? Quote::withoutGlobalScopes()->find($record->getId())
            : null;
    }

    private function getRecipientLabelForRecord(Quote|BaseOrder $record): string
    {
        $quote = $this->resolveQuote($record);
        if ($quote === null) {
            return 'klant';
        }
        $additional = $quote->getAdditional() ?? [];
        $billingKey = $additional['billing_address_type_key'] ?? null;
        if ($billingKey === null || $billingKey === '') {
            $type = $quote->getBillingAddressType();
            $billingKey = $type?->value ?? '';
        }
        if ($billingKey !== '' && (str_starts_with((string) $billingKey, 'company') || $billingKey === 'company')) {
            return 'dealer';
        }

        return 'klant';
    }
}
