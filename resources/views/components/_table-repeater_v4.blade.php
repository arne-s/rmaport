@php
    use Filament\Support\Enums\Alignment;
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>

    @php
        $items = $getItems();

        $addAction = $getAction($getAddActionName());
        $addActionAlignment = $getAddActionAlignment();
        $cloneAction = $getAction($getCloneActionName());
        $deleteAction = $getAction($getDeleteActionName());
        $moveDownAction = $getAction($getMoveDownActionName());
        $moveUpAction = $getAction($getMoveUpActionName());
        $reorderAction = $getAction($getReorderActionName());

        $isAddable = $isAddable();
        $isCloneable = $isCloneable();
        $isCollapsible = $isCollapsible();//
        $isDeletable = $isDeletable();
        $isReorderable = $isReorderable();
        $isReorderableWithButtons = $isReorderableWithButtons();
        $isReorderableWithDragAndDrop = $isReorderableWithDragAndDrop();

        $statePath = $getStatePath();

    // Use custom getHeaders() for advanced header info (label, required, width, display, name)
    $headers = $getHeaders();
    $columnWidths = $getColumnWidths();
    $hasHiddenHeader = $shouldHideHeader();
        //---

    $addBetweenAction = $getAction($getAddBetweenActionName());
    $extraItemActions = $getExtraItemActions();

    @endphp

    <div
        {{-- x-data="{ state: $wire.entangle('{{ $getStatePath() }}') }"  --}}
        x-data="{ isCollapsed: @js($isCollapsed()) }"
        x-on:repeater-collapse.window="$event.detail === '{{ $getStatePath() }}' && (isCollapsed = true)"
        x-on:repeater-expand.window="$event.detail === '{{ $getStatePath() }}' && (isCollapsed = false)"

        {{
            $attributes
                ->merge($getExtraAttributes(), escape: false)
                ->class(['it-table-repeater'])
        }}
    >

        <div class="it-table-repeater-header">
            <div></div>
            @if ($isCollapsible)
                <div>
                    <button
                        x-on:click="isCollapsed = !isCollapsed"
                        type="button"
                        class="it-table-repeater-btn-collapse"
                    >
                        <x-heroicon-s-chevron-up class="w-4 h-4" x-show="! isCollapsed"/>
                        <span class="sr-only" x-show="! isCollapsed">
                            {{ __('filament-forms::components.repeater.actions.collapse.label') }}
                        </span>
                        <x-heroicon-s-chevron-down class="w-4 h-4" x-show="isCollapsed" x-cloak/>
                        <span class="sr-only" x-show="isCollapsed" x-cloak>
                            {{ __('filament-forms::components.repeater.actions.expand.label') }}
                        </span>
                    </button>
                </div>
            @endif
        </div>

        <div class="px-4{{ $isAddable? '' : ' py-2' }}">
            <table x-show="! isCollapsed">
                <thead @class([
                    'sr-only' => $hasHiddenHeader,
                ])>
                <tr>
                    @foreach ($headers as $key => $header)
                        @if ($header['display'])
                            <th
                                @if ($header['width'])
                                    style="width: {{ $header['width'] }}"
                                @endif
                            >
                                {{ $header['label'] }}
                                @if ($header['required'])
                                    <span class="whitespace-nowrap">
                                            <sup class="font-medium text-danger-700 dark:text-danger-400">*</sup>
                                        </span>
                                @endif
                            </th>
                        @else
                            <th class="hidden"></th>
                        @endif
                    @endforeach
                    @if (count($extraItemActions)||$isReorderableWithDragAndDrop || $isReorderableWithButtons || $isCloneable || $isDeletable)
                        <th></th>
                    @endif
                </tr>
                </thead>

                <tbody
                    @php
                        // Fix sortable
                        $shouldEnableSortable = $isReorderable || $isReorderableWithDragAndDrop;
                    @endphp
                    @if ($shouldEnableSortable)
                        x-sortable
                    x-on:end.stop="
                            console.log('Sortable end event fired', $event);
                            console.log('Sortable order:', $event.target.sortable.toArray());
                            console.log('Item key:', $event.item.getAttribute('x-sortable-item'));
                            $wire.reorderTable($event.target.sortable.toArray(), $event.item.getAttribute('x-sortable-item'));
                        "
                    x-on:start="console.log('Sortable start event fired', $event)"
                    x-on:sort="console.log('Sortable sort event fired', $event)"
                    @endif
                >
                @if (count($items))
                    @foreach ($items as $itemKey => $item)
                        <tr
                            x-on:repeater-collapse.window="$event.detail === '{{ $getStatePath() }}' && (isCollapsed = true)"
                            x-on:repeater-expand.window="$event.detail === '{{ $getStatePath() }}' && (isCollapsed = false)"
                            wire:key="{{ $item->getLivewireKey() }}.item"
                            x-sortable-item="{{ $itemKey }}"
                        >
                            @foreach($item->getComponents(withHidden: true) as $component)
                                @if(! $component instanceof \Filament\Forms\Components\Hidden && ! $component->isHidden())
                                    <td
                                        @if ($columnWidths && isset($columnWidths[$component->getName()]))
                                            style="width: {{ $columnWidths[$component->getName()] }}"
                                        @endif
                                    >
                                        {{ $component }}
                                    </td>
                                @else
                                    {{ $component }}
                                @endif
                            @endforeach
                            @if (count($extraItemActions)||$isReorderableWithDragAndDrop || $isReorderableWithButtons || $isCloneable || $isDeletable )
                                <td class="it-table-repeater-actions">
                                    @foreach ($extraItemActions as $extraItemAction)
                                        <div x-on:click.stop>
                                            {{ $extraItemAction(['item' => $itemKey]) }}
                                        </div>
                                    @endforeach
                                    @if ($isReorderableWithDragAndDrop || $isReorderableWithButtons)
                                        @if ($isReorderableWithDragAndDrop)
                                            <div x-sortable-handle x-on:click.stop>
                                                {{ $reorderAction }}
                                            </div>
                                        @endif
                                        @if ($isReorderableWithButtons)
                                            <div class="flex items-center justify-center">
                                                {{ $moveUpAction(['item' => $itemKey])->disabled($loop->first) }}
                                            </div>
                                            <div class="flex items-center justify-center">
                                                {{ $moveDownAction(['item' => $itemKey])->disabled($loop->last) }}
                                            </div>
                                        @endif
                                    @endif
                                    @if ($isCloneable || $isDeletable )
                                        @if ($cloneAction->isVisible())
                                            <div>
                                                {{ $cloneAction(['item' => $itemKey]) }}
                                            </div>
                                        @endif
                                        @if ($isDeletable)
                                            <div>
                                                {{ $deleteAction(['item' => $itemKey]) }}
                                            </div>
                                        @endif
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="{{ count($headers) + (count($extraItemActions)||$isReorderableWithDragAndDrop || $isReorderableWithButtons || $isCloneable || $isDeletable ? 1 : 0) }}" class="it-table-repeater-column p-4 w-px text-center italic">
                            {{ $getEmptyLabel() ?? __('filament-table-repeater::components.repeater.empty.label') }}
                        </td>
                    </tr>
                @endif
                </tbody>
            </table>
            <div class="it-table-repeater-collapsed" x-show="isCollapsed" x-cloak>
                {{ __('filament-table-repeater::components.table-repeater.collapsed') }}
            </div>
        </div>

        @if ($isAddable && $addAction->isVisible())
            <div
                @class([
                    'it-table-repeater-add',
                    match ($addActionAlignment) {
                        Alignment::Start, Alignment::Left => 'justify-start',
                        Alignment::Center, null => 'justify-center',
                        Alignment::End, Alignment::Right => 'justify-end',
                        default => $alignment,
                    },
                ])
            >
                {{ $addAction }}
            </div>
        @endif

    </div>

</x-dynamic-component>
