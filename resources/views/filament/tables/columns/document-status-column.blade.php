<div class="filament-tables-text-column px-4 py-3 padding-8 flex">
    {{ Str::title($getOrderStatusFormatted()) }}
    <span title="{{ Str::title($getOrderStatusFormatted()) }}"
          class="icon-status type-{{ $getOrderType() }} status-{{ $getOrderStatus() }}">⬤</span>
</div>