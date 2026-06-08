<x-filament-widgets::widget class="quick-links-widget">
    @php($quickLinks = $this->getLinks())

    @if ($quickLinks !== [])
        <div class="quick-links-widget__frame">
            <section class="quick-links-widget__heading actions">
                <strong>Snelle acties</strong>
                <p>Veelgebruikte handelingen</p>
            </section>

            <div class="quick-links-widget__actions">
                @foreach ($quickLinks as $action)
                    {{ $action }}
                @endforeach
            </div>
        </div>
    @endif

    <x-filament-actions::modals />
</x-filament-widgets::widget>
