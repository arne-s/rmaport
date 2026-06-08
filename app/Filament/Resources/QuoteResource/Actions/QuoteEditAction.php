<?php

namespace App\Filament\Resources\QuoteResource\Actions;

use App\Enums\OrderGeneralStatus;
use App\Exceptions\QuoteRevisionAlreadyStartedException;
use App\Filament\Support\RecordLockNavigation;
use App\Models\Order\BaseOrder;
use App\Models\Order\Quote;
use Filament\Actions\Action;
use Filament\Actions\Concerns\CanCustomizeProcess;
use Livewire\Component;

/**  @method Quote|BaseOrder getRecord() */
class QuoteEditAction extends Action
{
    use CanCustomizeProcess;

    public static function getDefaultName(): ?string
    {
        return 'quote_edit';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->button()
            ->label(fn (Quote|BaseOrder $record) => $this->resolveQuote($record)?->status === OrderGeneralStatus::Expired
                ? 'Herzien'
                : '');

        $this->modalHeading(fn (Quote|BaseOrder $record) => $this->resolveQuote($record)?->status === OrderGeneralStatus::Expired
            ? 'Herzien'
            : 'Aanpassen');

        $this->visible(fn (Quote|BaseOrder $record): bool => $this->resolveQuote($record) !== null
            && in_array($this->resolveQuote($record)->status, [OrderGeneralStatus::Expired, OrderGeneralStatus::Pending, OrderGeneralStatus::Sent], true));

        $this->extraAttributes(function (Quote|BaseOrder $record) {
            $quote = $this->resolveQuote($record);
            if ($quote === null) {
                return ['class' => 'button-blue'];
            }
            $class = $quote->status === OrderGeneralStatus::Expired ? 'button-red' : 'button-blue';
            $class .= $quote->status === OrderGeneralStatus::Completed ? ' disabled' : '';

            return ['class' => $class];
        });

        $this->action(function (): void {
            $this->process(function (array $data, Quote|BaseOrder $record) {
                $quote = $this->resolveQuote($record);
                if ($quote === null) {
                    return;
                }

                try {
                    $changedQuote = $quote->changeQuote();
                    $livewire = $this->getLivewire();

                    if ($livewire instanceof Component) {
                        RecordLockNavigation::attemptRedirectToEdit(
                            $livewire,
                            $changedQuote,
                            route('filament.app.resources.quotes.edit', ['record' => $changedQuote->getId()]),
                        );
                    }
                } catch (QuoteRevisionAlreadyStartedException $e) {
                    RecordLockNavigation::notifyRevisionAlreadyStarted('offerte', $e->startedByUserName);
                }
            });
        });

        $this->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    private function resolveQuote(Quote|BaseOrder $record): ?Quote
    {
        if ($record instanceof Quote) {
            return $record;
        }
        if ((string) ($record->type?->value ?? $record->type ?? '') === 'order') {
            return null;
        }
        $typeValue = $record->type instanceof \BackedEnum ? $record->type->value : (string) ($record->type ?? '');

        return $typeValue === 'quote' ? Quote::withoutGlobalScopes()->find($record->getId()) : null;
    }
}
