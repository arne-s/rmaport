@props(['orderProductId' => null])
@php
    $record = $this->orderProducts->get($orderProductId) ?? null;

    $enabled = $record !== null
        && $record['_useProductBuilder'];

    $highlight = $record && $record['_requiresProductBuilder'] && !$record['_hasUsedProductBuilder'];
    $recordId = $record?->id ?? $record['id'] ?? null;
@endphp


@if ($enabled && $recordId)
    <a href="#" class="configure-button{{ $highlight ? ' highlight' : '' }}" wire:click.prevent="$dispatch('showQuoteEditorModal', { product: {{ $recordId }} })">
        <img src="/img/setting.png">
    </a>
@else
    <a href="#" class="configure-button disabled">
        <img src="/img/setting.png">
    </a>
@endif
