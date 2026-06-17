@props([
    'actions' => [],
    'breadcrumbs' => [],
    'heading',
    'subheading' => null,
])

<header
    {{
        $attributes->class([
            'fi-header',
            'fi-header-has-breadcrumbs' => $breadcrumbs,
        ])
    }}
>
    @php
        $params = request()->method() === 'POST'
            ? request()->headers->get('referer')
            : request()->getQueryString();
    @endphp
    @if (! str_contains($params ?? '', 'minimal') && $breadcrumbs)
        @teleport('#customBreadcrumbs')
            <x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />
        @endteleport
    @endif

    @if (isset($this->showHeading) && $this->showHeading)
        <div>
            <x-filament::header.heading>
                {{ $heading }}
            </x-filament::header.heading>

            @if ($subheading)
                <x-filament::header.subheading class="mt-1">
                    {{ $subheading }}
                </x-filament::header.subheading>
            @endif
        </div>
    @endif

    @php
        $beforeActions = \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, scopes: $this->getRenderHookScopes());
        $afterActions = \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_ACTIONS_AFTER, scopes: $this->getRenderHookScopes());
    @endphp

    @if (filled($beforeActions) || $actions || filled($afterActions))
        <div class="fi-header-actions-ctn status-overview">
            {{ $beforeActions }}

            @if ($actions)
                <x-filament::actions :actions="$actions" />
            @endif

            {{ $afterActions }}
        </div>
    @endif
</header>
