<div class="filament-tables-text-column px-4 py-3 padding-8 flex">
    {{ Str::of($getProductName())->limit(80) }}
    <span class="icon-status status-{{ $getProductStatus() ? 'active' : 'inactive' }}">⬤</span>
</div>
