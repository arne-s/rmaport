@props([
    'breadcrumbs' => [],
])

@php
    $breadcrumbs = array_filter(
        $breadcrumbs,
        fn (mixed $label): bool => filled($label),
    );
@endphp

<div {{ $attributes->class(['filament-breadcrumbs flex-1']) }}>

    <img width="105" src="/img/logo.svg">

    <ul @class([
        'gap-4 items-center font-medium text-sm flex',
        'dark:text-white' => config('filament.dark_mode'),
    ])>
        @foreach ($breadcrumbs as $url => $label)
            <li>
                <a
                    href="{{ is_int($url) ? '#' : $url }}"
                    @class([
                        'text-gray-500 subpage' => $loop->last && (! $loop->first),
                        'dark:text-gray-300' => ((! $loop->last) || $loop->first) && config('filament.dark_mode'),
                        'dark:text-gray-400' => $loop->last && (! $loop->first) && config('filament.dark_mode'),
                    ])
                >
                    {{ $label }}
                </a>
            </li>

            @if (! $loop->last)
                <li @class([
                    'h-6 border-r border-gray-300 -skew-x-12',
                    'dark:border-gray-500' => config('filament.dark_mode'),
                ])></li>
            @endif
        @endforeach
    </ul>
</div>
