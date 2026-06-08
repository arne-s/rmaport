{{--
    Desktop topbar Reporting: items onder submenu 'Reporting', elk met hetzelfde icoon als Inkoopbevestigingen
    (/img/icons/user-menu/file-lines-solid.svg). Moet onder subMenuInner staan voor adminMenu.scss (mainItem / icon-styles).
--}}
@php
    $reportingMenuIcon = asset('/img/icons/user-menu/file-lines-solid.svg');
@endphp

<a href="{{ route('filament.app.resources.reporting.revenue') }}" class="subMenuItem {{ $topMenuLinkActive(route('filament.app.resources.reporting.revenue')) }}">
    <div class="mainItem no-link">
        <div class="mainItem-info">
            <img class="mainItem-icon" src="{{ $reportingMenuIcon }}" alt="">
            <span class="mainItem-name">Commercieel</span>
        </div>
    </div>
</a>
<a href="{{ route('filament.app.resources.margin-overview.index') }}" class="subMenuItem {{ $topMenuLinkActive(route('filament.app.resources.margin-overview.index')) }}">
    <div class="mainItem no-link">
        <div class="mainItem-info">
            <img class="mainItem-icon" src="{{ $reportingMenuIcon }}" alt="">
            <span class="mainItem-name">Marge orders</span>
        </div>
    </div>
</a>
<a href="{{ route('filament.app.resources.product-revenue.index') }}" class="subMenuItem {{ $topMenuLinkActive(route('filament.app.resources.product-revenue.index')) }}">
    <div class="mainItem no-link">
        <div class="mainItem-info">
            <img class="mainItem-icon" src="{{ $reportingMenuIcon }}" alt="">
            <span class="mainItem-name">Omzet artikelen</span>
        </div>
    </div>
</a>
<a href="{{ route('filament.app.resources.product-stock.index') }}" class="subMenuItem {{ $topMenuLinkActive(route('filament.app.resources.product-stock.index')) }}">
    <div class="mainItem no-link">
        <div class="mainItem-info">
            <img class="mainItem-icon" src="{{ $reportingMenuIcon }}" alt="">
            <span class="mainItem-name">Voorraad</span>
        </div>
    </div>
</a>
@if (filament()->auth()->check() && filament()->auth()->user()->hasRole(['manager', 'Super Admin']))
    <a href="{{ route('filament.app.resources.note-reporting.index') }}" class="subMenuItem {{ $topMenuLinkActive(route('filament.app.resources.note-reporting.index')) }}">
        <div class="mainItem no-link">
            <div class="mainItem-info">
                <img class="mainItem-icon" src="{{ $reportingMenuIcon }}" alt="">
                <span class="mainItem-name">Notities</span>
            </div>
        </div>
    </a>
@endif
