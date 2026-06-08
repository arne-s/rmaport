<div class="toggle-filter" {!! $getId() ? "id=\"{$getId()}\"" : null !!}
{{ $attributes->merge($getExtraAttributes())->class([
    'filament-forms-fieldset-component rounded-xl shadow-xs border border-gray-300 p-6',
    'dark:border-gray-600 dark:text-gray-200' => config('forms.dark_mode'),
]) }} :class="{ 'open': show }" x-on:click.away="show=false" x-on:keydown.escape.window="show=false" x-data="{show: false}">
    <div class="wrapper">
        <div class="label" x-on:click="show = !show">
            {{ $getLabel()  }}
        </div>

        <div class="options">
            {{ $getChildComponentContainer() }}
        </div>
    </div>
</div>
