{{--
    Mobiel zijmenu Reporting: platte links in subitems-list.
    Gebruikt dezelfde routes/order als reporting-submenu-content (desktop).
--}}
<a href="{{ route('filament.app.resources.reporting.revenue') }}">Commercieel</a>
<a href="{{ route('filament.app.resources.reporting.statistics') }}">Statistieken</a>
<a href="{{ route('filament.app.resources.margin-overview.index') }}">Marge orders</a>
<a href="{{ route('filament.app.resources.main-reporting.index') }}">Voortgang</a>
<a href="{{ route('filament.app.resources.unit-orders.index') }}">Units per maand</a>
<a href="{{ route('filament.app.resources.unit-invoicing.index') }}">Unit factuuroverzicht</a>
<a href="{{ route('filament.app.resources.product-revenue.index') }}">Omzet artikelen</a>
<a href="{{ route('filament.app.resources.supplier-revenue.index') }}">Omzet per leverancier</a>
<a href="{{ route('filament.app.resources.product-stock.index') }}">Voorraad</a>
<a href="{{ route('filament.app.resources.serial-numbers.index') }}">Serienummers</a>
@if (filament()->auth()->check() && filament()->auth()->user()->hasRole(['manager', 'Super Admin']))
    <a href="{{ route('filament.app.resources.note-reporting.index') }}">Notities</a>
@endif
