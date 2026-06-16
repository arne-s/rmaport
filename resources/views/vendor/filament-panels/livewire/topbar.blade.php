@php
    /**
     * Desktop Menu in topbar
     */

    $topMenuUrl = rtrim(strtok((string) request()->url(), '?'), '/');
    $topMenuLinkActive = static function (string $url) use ($topMenuUrl): string {
        return rtrim(strtok($url, '?'), '/') === $topMenuUrl ? 'pageActive' : '';
    };

    $topMenuRetourenActive = request()->routeIs(
        'filament.app.resources.rmas.*',
        'filament.app.resources.import-rows.*',
        'filament.app.resources.import-tasks.*',
    );
    $topMenuRelatiesActive = request()->routeIs(
        'filament.app.resources.customers.*',
    );
    $topMenuVerkoopActive = request()->routeIs(
        'filament.app.resources.production.*',
        'filament.app.resources.mains.*',
        'filament.app.resources.quotes.*',
        'filament.app.resources.orders.*',
    );
    $topMenuInkoopActive = request()->routeIs(
        'filament.app.resources.suppliers.*',
    );
    $topMenuArtikelenActive = request()->routeIs(
        'filament.app.resources.products.*',
    );
    $topMenuFinancieelActive = request()->routeIs(
        'filament.app.resources.invoices.*',
        'filament.app.resources.credit-invoices.*',
        'filament.app.resources.recurring-invoices.*',
    );
    $topMenuReportingActive = request()->routeIs(
        'filament.app.resources.reporting.*',
        'filament.app.resources.margin-overview.*',
        'filament.app.resources.note-reporting.*',
        'filament.app.resources.product-revenue.*',
        'filament.app.resources.product-stock.*',
    );
    $topMenuAdminActive = request()->routeIs(
        'filament.app.resources.manager.*',
        'filament.app.resources.roles.*',
        'filament.app.resources.permissions.*',
        'filament.app.resources.email-templates.*',
        'filament.app.resources.mail-logs.*',
    )
        || ($topMenuUrl === rtrim(strtok(route('filament.app.resources.customers.settings'), '?'), '/'));

    $authUser = filament()->auth()->user();
    $showAdminMenu = $authUser && (
        $authUser->can('manage users')
        || $authUser->isSuperAdmin()
        || $authUser->can('manage settings')
    );
@endphp

@props([
    'breadcrumbs' => [],
])

@include('filament.partials.database-notification-topbar-styles')

<div x-data>
    <script>
        document.addEventListener('alpine:init', function () {
            if (!Alpine.store('mobileSidebar')) {
                Alpine.store('mobileSidebar', { open: false });
            }
        });
    </script>

    <header {{ $attributes->class([
        'filament-main-topbar sticky top-0 z-10 flex h-16 w-full shrink-0 items-center bg-white',
        'dark:bg-gray-800 dark:border-gray-700' => config('filament.dark_mode'),
    ]) }}>
        <div class="flex items-center w-full px-2 sm:px-4 md:px-6 lg:px-8">
            <button
                x-cloak
                x-data="{}"
                x-bind:aria-label="$store.sidebar.isOpen ? '{{ __('filament::layout.buttons.sidebar.collapse.label') }}' : '{{ __('filament::layout.buttons.sidebar.expand.label') }}'"
                x-on:click="
                    $store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open();
                    $store.mobileSidebar.open = !$store.mobileSidebar.open;
                "
                @class([
                    'filament-sidebar-open-button shrink-0 flex items-center justify-center w-10 h-10 text-primary-500 rounded-full outline-hidden hover:bg-gray-500/5 focus:bg-primary-500/10',
                    'lg:mr-4 rtl:lg:mr-0 rtl:lg:ml-4'=> config('filament.layout.sidebar.is_collapsible_on_desktop'),
                    'lg:hidden' => !(config('filament.layout.sidebar.is_collapsible_on_desktop') && (config('filament.layout.sidebar.collapsed_width') === 0)),
                ])
            >
                <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </button>

            {{-- Single toolbar cluster (desktop + mobile): avoids duplicate Livewire components — database notifications modal used id="database-notifications" twice in the DOM. --}}
            <div class="topbar-toolbar-cluster flex min-w-0 flex-1 items-center gap-2">
                <div id="customBreadcrumbs" class="filament-breadcrumbs min-w-0 flex-1 hide-on-mobile">

                </div>

                <div class="mobileheader-hasmenu ml-auto flex shrink-0 items-center justify-end">
                    @if (filament()->isGlobalSearchEnabled())
                        <div class="topbar-global-search-wrap min-w-0 hide-on-mobile">
                            @livewire(Filament\Livewire\GlobalSearch::class)
                        </div>
                    @endif

                    <a href="{{ route('filament.app.pages.dashboard') }}" class="mobileheader-logo hide-on-desktop">
                        <img src="{{ asset('/img/logo.svg') }}" alt="Logo">
                    </a>

                    <div class="notificationMenuButton">
                        <livewire:filament.chat-topbar-button />
                    </div>

                    @if (filament()->hasDatabaseNotifications())
                        <div class="notificationMenuButton">
                            @livewire(filament()->getDatabaseNotificationsLivewireComponent(), [
                                'lazy' => filament()->hasLazyLoadedDatabaseNotifications(),
                            ])
                        </div>
                    @endif

                    @if (filament()->hasUserMenu())
                        <x-filament-panels::user-menu />
                    @endif
                </div>
            </div>
        </div>
    </header>

    <div class="mobileheader-search hide-on-desktop">
        @if (filament()->isGlobalSearchEnabled())
            @livewire(Filament\Livewire\GlobalSearch::class)
        @endif
    </div>

    <livewire:global-edit-note />
    @can('create main orders')
        <livewire:global-create-main />
    @endcan
    <livewire:global-quote-preview-placeholder />

    <div class="customMenuHeader flex items-center w-full px-2 sm:px-4 md:px-6 lg:px-8">
        <div class="customMenuContainer">
            <div class="customMenuInner">
                <div class="menuItem">
                    <a class="menuItemLink {{ $topMenuLinkActive(route('filament.app.pages.dashboard')) }}" href="{{ route('filament.app.pages.dashboard') }}">
                        @svgImg('img/icons/user-menu/home.svg')
                        <span class="menuItemText" data-text="Dashboard">Dashboard</span>
                    </a>
                </div>

                @can('manage sales')
                <div class="menuItem">
                    <a class="menuItemLink parent {{ $topMenuRetourenActive ? 'pageActive' : '' }}">
                        <span class="menuItemText" data-text="Retouren">Retouren</span>
                    </a>

                    <div class="subMenu-new">
                        <div class="subMenuInner submenuitem">
                            <a href="{{ route('filament.app.resources.rmas.index') }}" class="subMenuItem {{ $topMenuLinkActive(route('filament.app.resources.rmas.index')) }}">
                                <div class="mainItem no-link">
                                    <div class="mainItem-info">
                                        <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/box-open-solid.svg') }}" alt="menu-icon">
                                        <span class="mainItem-name">Overzicht</span>
                                    </div>
                                </div>
                            </a>
                            <a href="{{ route('filament.app.resources.rmas.create') }}" class="subMenuItem {{ $topMenuLinkActive(route('filament.app.resources.rmas.create')) }}">
                                <div class="mainItem no-link">
                                    <div class="mainItem-info">
                                        <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/box-open-solid.svg') }}" alt="menu-icon">
                                        <span class="mainItem-name">RMA aanmaken</span>
                                    </div>
                                </div>
                            </a>
                            <div class="subMenuItem">
                                <div class="mainItem">
                                    <div class="mainItem-info">
                                        <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/box-open-solid.svg') }}" alt="menu-icon">
                                        <span class="mainItem-name">Imports</span>
                                    </div>
                                </div>

                                <div class="mainItemAllLinks">
                                    <a class="subMenuItemLink {{ $topMenuLinkActive(route('filament.app.resources.import-tasks.index')) }}" href="{{ route('filament.app.resources.import-tasks.index') }}">
                                        <span class="menuItemText" data-text="Importtaken">Importtaken</span>
                                    </a>
                                    <a class="subMenuItemLink {{ $topMenuLinkActive(route('filament.app.resources.import-rows.index')) }}" href="{{ route('filament.app.resources.import-rows.index') }}">
                                        <span class="menuItemText" data-text="Importrijen">Importrijen</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endcan

                @can('manage customers')
                <div class="menuItem">
                    <a class="menuItemLink {{ $topMenuRelatiesActive ? 'pageActive' : '' }}" href="{{ route('filament.app.resources.customers.index') }}">
                        <span class="menuItemText" data-text="Klanten">Klanten</span>
                    </a>
                </div>
                @endcan

                @can('manage sales')
                <div class="menuItem">
                    <a class="menuItemLink parent {{ $topMenuVerkoopActive ? 'pageActive' : '' }}">
                        <span class="menuItemText" data-text="Verkoop">Verkoop</span>
                    </a>

                    <div class="subMenu-new">
                        <div class="subMenuInner submenuitem">
                            <a href="#" class="subMenuItem" x-on:click.prevent>
                                <div class="mainItem no-link">
                                    <div class="mainItem-info">
                                        <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/arrows-rotate.svg') }}" alt="menu-icon">
                                        <span class="mainItem-name"><strong>Verkoopproces</strong></span>
                                    </div>
                                </div>
                            </a>
                            <div class="subMenuItem">
                                <div class="mainItem">
                                    <div class="mainItem-info">
                                        <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/file-lines-solid.svg') }}" alt="menu-icon">
                                        <span class="mainItem-name">Offertes</span>
                                    </div>
                                </div>

                                <div class="mainItemAllLinks">
                                    <a class="subMenuItemLink {{ $topMenuLinkActive(route('filament.app.resources.quotes.index')) }}" href="{{ route('filament.app.resources.quotes.index') }}">
                                        <span class="menuItemText" data-text="Overzicht">Overzicht</span>
                                    </a>
                                    @can('create main orders')
                                        <a
                                            class="subMenuItemLink"
                                            href="#"
                                            x-on:click.prevent="Livewire.dispatch('open-create-main-quote')"
                                        >
                                            <span class="menuItemText" data-text="Offerte aanmaken">Offerte aanmaken</span>
                                        </a>
                                    @endcan
                                </div>
                            </div>
                            <div class="subMenuItem">
                                <div class="mainItem">
                                    <div class="mainItem-info">
                                        <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/cart-shopping-solid.svg') }}" alt="menu-icon">
                                        <span class="mainItem-name">Orders</span>
                                    </div>
                                </div>

                                <div class="mainItemAllLinks">
                                    <a class="subMenuItemLink {{ $topMenuLinkActive(route('filament.app.resources.orders.index')) }}" href="{{ route('filament.app.resources.orders.index') }}">
                                        <span class="menuItemText" data-text="Overzicht">Overzicht</span>
                                    </a>
                                    @can('create main orders')
                                    <a
                                        class="subMenuItemLink"
                                        href="#"
                                        x-on:click.prevent="Livewire.dispatch('open-create-main-order')"
                                    >
                                        <span class="menuItemText" data-text="Order aanmaken">Order aanmaken</span>
                                    </a>
                                    @endcan
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endcan

                @can('manage purchases')
                <div class="menuItem">
                    <a class="menuItemLink parent {{ $topMenuInkoopActive ? 'pageActive' : '' }}">
                        <span class="menuItemText" data-text="Inkoop">Inkoop</span>
                    </a>

                    <div class="subMenu-new">
                        <div class="subMenuInner submenuitem">
                            <a href="{{ route('filament.app.resources.suppliers.index') }}" class="subMenuItem {{ $topMenuLinkActive(route('filament.app.resources.suppliers.index')) }}">
                                <div class="mainItem no-link">
                                    <div class="mainItem-info">
                                        <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/store-solid.svg') }}" alt="menu-icon">
                                        <span class="mainItem-name">Leveranciers</span>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
                @endcan

                @can('manage products')
                <div class="menuItem">
                    <a class="menuItemLink parent {{ $topMenuArtikelenActive ? 'pageActive' : '' }}">
                        <span class="menuItemText" data-text="Artikelen">Artikelen</span>
                    </a>

                    <div class="subMenu-new">
                        <div class="subMenuInner one-row">
                            <a href="{{ route('filament.app.resources.products.index') }}" class="subMenuItem {{ $topMenuLinkActive(route('filament.app.resources.products.index')) }}">
                                <div class="mainItem no-link">
                                    <div class="mainItem-info">
                                        <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/tag-solid.svg') }}" alt="menu-icon">
                                        <span class="mainItem-name">Artikelen</span>
                                    </div>
                                </div>
                            </a>

                        </div>
                    </div>
                </div>
                @endcan

                @can('manage financials')
                <div class="menuItem">
                    <a class="menuItemLink parent {{ $topMenuFinancieelActive ? 'pageActive' : '' }}">
                        <span class="menuItemText" data-text="Financieel">Financieel</span>
                    </a>

                    <div class="subMenu-new">
                        <div class="subMenuInner submenuitem">
                            <div class="subMenuItem">
                                <div class="mainItem">
                                    <div class="mainItem-info">
                                        <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/receipt-solid.svg') }}" alt="menu-icon">
                                        <span class="mainItem-name">Verkoopfacturen</span>
                                    </div>
                                </div>

                                <div class="mainItemAllLinks">
                                    <a class="subMenuItemLink {{ $topMenuLinkActive(route('filament.app.resources.invoices.index')) }}" href="{{ route('filament.app.resources.invoices.index') }}">
                                        <span class="menuItemText" data-text="Overzicht">Overzicht</span>
                                    </a>
                                    <a class="subMenuItemLink {{ $topMenuLinkActive(route('filament.app.resources.invoices.create')) }}" href="{{ route('filament.app.resources.invoices.create') }}">
                                        <span class="menuItemText" data-text="Factuur aanmaken">Factuur aanmaken</span>
                                    </a>
                                </div>
                            </div>

                            <div class="subMenuItem">
                                <div class="mainItem">
                                    <div class="mainItem-info">
                                        <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/receipt-solid.svg') }}" alt="menu-icon">
                                        <span class="mainItem-name">Abonnementen</span>
                                    </div>
                                </div>

                                <div class="mainItemAllLinks">
                                    <a class="subMenuItemLink {{ $topMenuLinkActive(route('filament.app.resources.recurring-invoices.index')) }}" href="{{ route('filament.app.resources.recurring-invoices.index') }}">
                                        <span class="menuItemText" data-text="Overzicht">Overzicht</span>
                                    </a>
                                    <a class="subMenuItemLink {{ $topMenuLinkActive(route('filament.app.resources.recurring-invoices.create')) }}" href="{{ route('filament.app.resources.recurring-invoices.create') }}">
                                        <span class="menuItemText" data-text="Abonnement aanmaken">Abonnement aanmaken</span>
                                    </a>
                                </div>
                            </div>

                            <a href="{{ route('filament.app.resources.credit-invoices.index') }}" class="subMenuItem {{ $topMenuLinkActive(route('filament.app.resources.credit-invoices.index')) }}">
                                <div class="mainItem no-link">
                                    <div class="mainItem-info">
                                        <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/receipt-solid.svg') }}" alt="menu-icon">
                                        <span class="mainItem-name">Creditfacturen</span>
                                    </div>
                                </div>
                            </a>

                        </div>
                    </div>
                </div>
                @endcan

                @can('manage reporting')
                <div class="menuItem">
                    <a class="menuItemLink parent {{ $topMenuReportingActive ? 'pageActive' : '' }}">
                        <span class="menuItemText" data-text="Reporting">Reporting</span>
                    </a>

                    <div class="subMenu-new">
                        <div class="subMenuInner submenuitem">
                            @include('filament.partials.reporting-submenu-content')
                        </div>
                    </div>
                </div>
                @endcan

                @if ($showAdminMenu)
                <div class="menuItem">
                    <a class="menuItemLink parent {{ $topMenuAdminActive ? 'pageActive' : '' }}">
                        <span class="menuItemText" data-text="Admin">Admin</span>
                    </a>

                    <div class="subMenu-new">
                        <div class="subMenuInner one-row">
                            @can('manage users')
                                <div class="subMenuItem">
                                    <div class="mainItem">
                                        <div class="mainItem-info">
                                            <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/user-shield-solid.svg') }}" alt="menu-icon">
                                            <span class="mainItem-name">Gebruikers</span>
                                        </div>
                                    </div>

                                    <div class="mainItemAllLinks">
                                        <a class="subMenuItemLink {{ $topMenuLinkActive(route('filament.app.resources.manager.index')) }}" href="{{ route('filament.app.resources.manager.index') }}">
                                            <span class="menuItemText" data-text="Overzicht">Overzicht</span>
                                        </a>
                                    </div>
                                </div>

                                <div class="subMenuItem">
                                    <div class="mainItem">
                                        <div class="mainItem-info">
                                            <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/user-shield-solid.svg') }}" alt="menu-icon">
                                            <span class="mainItem-name">Rollen &amp; permissies</span>
                                        </div>
                                    </div>

                                    <div class="mainItemAllLinks">
                                        <a class="subMenuItemLink {{ $topMenuLinkActive(route('filament.app.resources.roles.index')) }}" href="{{ route('filament.app.resources.roles.index') }}">
                                            <span class="menuItemText" data-text="Rollen">Rollen</span>
                                        </a>
                                        <a class="subMenuItemLink {{ $topMenuLinkActive(route('filament.app.resources.permissions.index')) }}" href="{{ route('filament.app.resources.permissions.index') }}">
                                            <span class="menuItemText" data-text="Permissies">Permissies</span>
                                        </a>
                                    </div>
                                </div>
                            @endcan

                            @can('manage settings')
                            <a href="{{ route('filament.app.resources.customers.settings') }}" class="subMenuItem {{ $topMenuLinkActive(route('filament.app.resources.customers.settings')) }}">
                                <div class="mainItem no-link">
                                    <div class="mainItem-info">
                                        <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/folder-solid.svg') }}" alt="menu-icon">
                                        <span class="mainItem-name">Stamgegevens</span>
                                    </div>
                                </div>
                            </a>

                            <div class="subMenuItem">
                                <div class="mainItem">
                                    <div class="mainItem-info">
                                        <img class="mainItem-icon" src="{{ asset('/img/icons/user-menu/envelope-solid.svg') }}" alt="menu-icon">
                                        <span class="mainItem-name">E-mails</span>
                                    </div>
                                </div>

                                <div class="mainItemAllLinks border-bottom-left-radius">
                                    <a class="subMenuItemLink {{ $topMenuLinkActive(route('filament.app.resources.email-templates.index')) }}" href="{{ route('filament.app.resources.email-templates.index') }}">
                                        <span class="menuItemText" data-text="Overzicht">Templates</span>
                                    </a>
                                    <a class="subMenuItemLink {{ $topMenuLinkActive(route('filament.app.resources.mail-logs.index')) }}" href="{{ route('filament.app.resources.mail-logs.index') }}">
                                        <span class="menuItemText" data-text="Logs">Logs</span>
                                    </a>
                                </div>
                            </div>
                            @endcan

                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
