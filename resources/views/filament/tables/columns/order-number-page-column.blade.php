@props(['displayDate' => true, 'showDownload' => true])

@php
    use App\Models\Order\Main;
    use App\Enums\OrderGeneralStatus;
    use App\Enums\OrderType;

    $model = $getModel();
    if ($shouldLeaveEmpty()) {
        return;
    }

    $typeValue = $model?->type instanceof \BackedEnum ? $model->type->value : (string) ($model?->type ?? '');
    $routeName = $model instanceof Main
        ? 'filament.app.resources.mains.view'
        : 'filament.app.resources.orders.view';
@endphp
<div class="numberPlusDate">
    @if ($model && ! empty($model->uid) && $model->status != OrderGeneralStatus::Draft->value && $model->status != OrderGeneralStatus::Initial->value)
        <div class="linksDocuments">
            @if (! $showDownload)
                <a
                    class="openDocument main-request-number-link"
                    href="{{ route($routeName, $model->id) }}"
                >
                    {{ $model->uid }}
                </a>
            @elseif ($model instanceof Main)
                <a
                    class="openDocument main-request-number-link"
                    href="{{ route($routeName, $model->id) }}"
                >
                    {{ $model->uid }}
                </a>
                <a class="downloadDocument" href="{{ route('order.manager-export', ['order' => $model->id]) }}"></a>
            @elseif ($typeValue === OrderType::Quote->value)
                <span
                    class="openDocument"
                    x-on:click="$dispatch('open-modal', { id: 'open-document', orderId: '{{ $model->id }}', quotePreview: true })"
                >{{ $model->uid }}</span>
                <a class="downloadDocument" href="{{ route('documents.order-pdf-download', ['id' => $model->id]) }}"></a>
            @elseif ($typeValue === OrderType::Order->value)
                <span
                    class="openDocument"
                    x-on:click="$dispatch('open-modal', { id: 'open-document', orderId: '{{ $model->id }}', orderHtmlPreview: true })"
                >{{ $model->uid }}</span>
                <a class="downloadDocument" href="{{ route('documents.order-pdf-download', ['id' => $model->id]) }}"></a>
            @else
                <a
                    class="openDocument main-request-number-link"
                    href="{{ route($routeName, $model->id) }}"
                >
                    {{ $model->uid }}
                </a>
                <a class="downloadDocument" href="{{ route('order.manager-export', ['order' => $model->id]) }}"></a>
            @endif
        </div>
        @if ($displayDate && $model->sent_at)
            <span class="text-sm text-gray-500 date">{{ $model->sent_at->translatedFormat('j M Y (H:i)') }}</span>
        @endif
    @else
        <span class="text-gray-400 text-sm"><em>{{ $getPendingOrderNumberLabel() }}</em></span>
    @endif
</div>
