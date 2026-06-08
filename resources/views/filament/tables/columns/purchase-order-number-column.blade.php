@props(['displayDate' => true, 'showDownload' => true, 'linkClass' => 'openDocument'])

@php
    $model = $getModel();
    $record = $getRecord();
    $allowLinkPurchaseOrder = ($allowLinkPurchaseOrder ?? false) && $record instanceof \App\Models\PurchaseOrderInvoice;

    if (! $shouldLeaveEmpty() && $model instanceof \App\Models\PurchaseOrder) {
        $label = filled($model->reference_number)
            ? $model->reference_number
            : ('#' . $model->getId());
    } else {
        $label = '';
    }
@endphp
<div class="numberPlusDate">
    @if ($shouldLeaveEmpty())
        @if ($allowLinkPurchaseOrder)
        @can('manage purchases')
        <button
            type="button"
            wire:click.prevent.stop="openLinkPurchaseOrderModal({{ (int) $record->getKey() }})"
            wire:loading.attr="disabled"
            wire:target="openLinkPurchaseOrderModal"
            class="{{ $linkClass }} main-request-number-link purchase-order-link-empty-action text-sm bg-transparent border-0 p-0 cursor-pointer"
        >
            Inkooporder koppelen
        </button>
        @endcan
        @endif
    @elseif ($model && $label !== '')
        <div class="linksDocuments">
            @can('manage purchases')
            <a
                class="{{ $linkClass }}"
                href="{{ route('filament.app.resources.purchase-orders.view', ['record' => $model->getId()]) }}"
            >{{ $label }}</a>
            @else
            <span>{{ $label }}</span>
            @endcan
            @if ($showDownload && auth()->user()?->can('manage purchases'))
                <a
                    class="downloadDocument"
                    href="{{ route('documents.purchaseOrderPreviewDownload', ['purchaseOrder' => $model->getId()]) }}"
                ></a>
            @endif
        </div>
        @if ($displayDate && $model->created_at)
            <span class="text-sm text-gray-500 date">{{ $model->created_at->translatedFormat('j M Y (H:i)') }}</span>
        @endif
    @else
        <span class="text-gray-400 text-sm"><em>In behandeling...</em></span>
    @endif
</div>
