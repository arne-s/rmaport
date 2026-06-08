<x-filament::page
    :class="\Illuminate\Support\Arr::toCssClasses([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])"
>
    {{ \Filament\Facades\Filament::renderHook('resource.pages.list-records.table.start') }}

    {{ $this->table }}

    {{ \Filament\Facades\Filament::renderHook('resource.pages.list-records.table.end') }}
</x-filament::page>
