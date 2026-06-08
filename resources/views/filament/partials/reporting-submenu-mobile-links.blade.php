{{--
    Mobiel zijmenu Reporting: platte links in subitems-list.
    Gebruikt dezelfde routes/order als reporting-submenu-content (desktop).
--}}
<a href="{{ route('filament.app.resources.reporting.revenue') }}">Commercieel</a>
<a href="{{ route('filament.app.resources.margin-overview.index') }}">Marge orders</a>
<a href="{{ route('filament.app.resources.product-revenue.index') }}">Omzet artikelen</a>
<a href="{{ route('filament.app.resources.product-stock.index') }}">Voorraad</a>
@if (filament()->auth()->check() && filament()->auth()->user()->hasRole(['manager', 'Super Admin']))
    <a href="{{ route('filament.app.resources.note-reporting.index') }}">Notities</a>
@endif
