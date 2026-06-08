<?php

namespace App\Filament\Support;

use App\Filament\Resources\RecurringInvoices\Pages\EditRecurringInvoice;
use App\Models\OrderProduct;
use App\Models\RecurringInvoiceLine;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class OrderProductRepeaterConfiguration
{
    public static function apply(Repeater $repeater): Repeater
    {
        return $repeater
            ->addBetweenAction(fn (Action $action): Action => OrderProductRepeaterAddBetweenAction::configure($action))
            ->reorderable()
            ->reorderableWithDragAndDrop()
            ->reorderableWithButtons(false)
            ->partiallyRenderAfterActionsCalled()
            ->reorderAction(function (Action $action): Action {
                // Do not use ->hidden(): hidden actions are disabled and mountAction('reorder') aborts.
                return $action
                    ->action(function (array $arguments, Repeater $component): void {
                        $state = $component->getRawState();

                        if (! is_array($state)) {
                            return;
                        }

                        $items = [];

                        foreach ($arguments['items'] as $key) {
                            if (! is_string($key) && ! is_int($key)) {
                                continue;
                            }

                            if (! array_key_exists($key, $state)) {
                                continue;
                            }

                            $items[$key] = $state[$key];
                        }

                        foreach ($state as $key => $item) {
                            if (array_key_exists($key, $items)) {
                                continue;
                            }

                            $items[$key] = $item;
                        }

                        $component->rawState($items);

                        self::persistSortFromReorderedState($component, $items);
                        self::syncLivewireAfterReorder($component, $items);

                        $component->callAfterStateUpdated(shouldBubbleToParents: false);

                        if ($component->shouldPartiallyRenderAfterActionsCalled()) {
                            $component->partiallyRender();
                        }
                    });
            });
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $items
     */
    private static function persistSortFromReorderedState(Repeater $component, array $items): void
    {
        $sort = 1;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = (int) ($item['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $model = self::resolveLineModel($component, $id);

            if ($model === null) {
                continue;
            }

            $model->update(['sort' => $sort]);
            $sort++;
        }
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $items
     */
    private static function syncLivewireAfterReorder(Repeater $component, array $items): void
    {
        $livewire = $component->getLivewire();

        if (property_exists($livewire, 'orderProducts') && $livewire->orderProducts instanceof Collection) {
            $reordered = collect();

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = (int) ($item['id'] ?? 0);

                if ($id > 0 && $livewire->orderProducts->has($id)) {
                    $reordered->put($id, $livewire->orderProducts->get($id));
                }
            }

            $livewire->orderProducts = $reordered;
        }

        if (property_exists($livewire, 'recurringLines') && $livewire->recurringLines instanceof Collection) {
            $reordered = collect();

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = (int) ($item['id'] ?? 0);

                if ($id > 0 && $livewire->recurringLines->has($id)) {
                    $reordered->put($id, $livewire->recurringLines->get($id));
                }
            }

            $livewire->recurringLines = $reordered;
        }

        if (method_exists($livewire, 'getRecord')) {
            $record = $livewire->getRecord();

            if ($record !== null && method_exists($record, 'unsetRelation')) {
                $record->unsetRelation('orderProducts');
                $record->unsetRelation('lines');
            }
        }
    }

    private static function resolveLineModel(Repeater $component, int $id): ?Model
    {
        $livewire = $component->getLivewire();

        if ($livewire instanceof EditRecurringInvoice) {
            return RecurringInvoiceLine::query()->find($id);
        }

        return OrderProduct::query()->find($id);
    }
}
