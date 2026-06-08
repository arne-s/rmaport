@php
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
@endphp

<div @class(['fi-ta-actions', 'fi-align-center'])>
    {{
        \Filament\Support\generate_icon_html(
            Heroicon::Eye,
            size: IconSize::Small,
            attributes: new \Illuminate\View\ComponentAttributeBag([
                'class' => 'mail-log-view-action-icon',
            ]),
        )
    }}
</div>
