<x-filament-widgets::widget class="fi-wi-table dashboard-paired-table-widget">
    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\Widgets\View\WidgetsRenderHook::TABLE_WIDGET_START, scopes: static::class) }}

    <div class="my-appointments-widget-table">
        {{ $this->table ?? null }}
    </div>

    <script>
        (() => {
            if (window.__rdmMyAppointmentsMainLinkBound === true) {
                return;
            }

            window.__rdmMyAppointmentsMainLinkBound = true;

            document.addEventListener('click', (event) => {
                const target = event.target;
                if (! (target instanceof Element)) {
                    return;
                }

                if (target.closest('a, button, input, select, textarea')) {
                    return;
                }

                const cell = target.closest('td.my-appointments-main-link-cell[data-main-url]');
                if (!cell) {
                    return;
                }

                const url = (cell.getAttribute('data-main-url') || '').trim();
                if (url === '') {
                    return;
                }

                window.open(url, '_blank', 'noopener');
            });
        })();
    </script>

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\Widgets\View\WidgetsRenderHook::TABLE_WIDGET_END, scopes: static::class) }}
</x-filament-widgets::widget>
