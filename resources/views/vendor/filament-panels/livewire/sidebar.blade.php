@php
/**
* Mobile Menu
*/
$authUser = filament()->auth()->user();
$showAdminMenu = $authUser && (
$authUser->can('manage users')
|| $authUser->isSuperAdmin()
|| $authUser->can('manage settings')
);
@endphp

<div
    class="mobileheader-sidebar hide-on-desktop fixed top-0 left-0 h-full w-80 bg-white z-50 transition-transform duration-300"
    x-show="$store.mobileSidebar.open"
    x-transition:enter="transform transition duration-300"
    x-transition:enter-start="-translate-x-full"
    x-transition:enter-end="translate-x-0"
    x-transition:leave="transform transition duration-300"
    x-transition:leave-start="translate-x-0"
    x-transition:leave-end="-translate-x-full"
    @click.away="$store.mobileSidebar.open = false">
    <div class="mobileheader-sidebar-header">

        <h2>Menu</h2>

        <button
            x-on:click="$store.mobileSidebar.open = false; $store.sidebar.close();"
            aria-label="Sluit menu">
            <img src="{{ asset('/img/icons/close.png') }}" alt="menu-icon">
        </button>
    </div>

    <div class="mobileheader-sidebar-menu" x-data="{ activeMenu: null, subMenu: null }">
        <div class="sidebar-menuitem" x-show="activeMenu === null">
            <a class="sidebar-menuitem-wrapper" href="{{ route('filament.app.pages.dashboard') }}">
                <div class="sidebar-menuitem-info">
                    <img class="sidebar-menuitem-icon" src="{{ asset('/img/icons/user-menu/home.svg') }}" alt="menu-icon">
                    <span class="sidebar-menuitem-name">Dashboard</span>
                </div>
            </a>
        </div>


        <template x-if="activeMenu === null || activeMenu === 'relaties'">
            <div class="sidebar-menuitem">
                <a href="{{ route('filament.app.resources.customers.index') }}" class="sidebar-menuitem-wrapper">
                    <div class="sidebar-menuitem-info">
                        <img class="sidebar-menuitem-icon" src="{{ asset('/img/icons/user-menu/people-group-solid.svg') }}" alt="menu-icon">
                        <span class="sidebar-menuitem-name">Klanten</span>
                    </div>
                </a>
            </div>
        </template>

        @can('manage sales')
        <template x-if="activeMenu === null || activeMenu === 'verkoop'">
            <div class="sidebar-menuitem">
                <div class="sidebar-menuitem-wrapper" x-show="activeMenu === null" x-on:click="activeMenu = 'verkoop'">
                    <div class="sidebar-menuitem-info">
                        <img class="sidebar-menuitem-icon" src="{{ asset('/img/icons/user-menu/cart-shopping-solid.svg') }}" alt="menu-icon">
                        <span class="sidebar-menuitem-name">Verkoop</span>
                    </div>
                </div>

                <div class="menuitem-subitems" x-show="activeMenu === 'verkoop'"
                    x-transition:enter="transition-opacity duration-200"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100">
                    <button class="subitems-back" :class="{ 'mobielheader-marginbottom-none': activeMenu !== null }" x-on:click="activeMenu = null; subMenu = null">Alle categorieën</button>
                    <h2 x-show="!subMenu">Verkoop</h2>

                    <div class="subitems-list" x-show="!subMenu">
                        <a href="{{ route('filament.app.resources.production.index') }}"><strong>Verkoopproces</strong></a>
                        <div x-on:click="subMenu = 'passing'">Passing</div>
                        <div x-on:click="subMenu = 'offertes'">Offertes</div>
                        <div x-on:click="subMenu = 'orders'">Orders</div>
                    </div>

                    <div x-show="subMenu === 'passing'"
                        x-transition:enter="transition-opacity duration-200"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100">
                        <button class="subitems-back" x-on:click="subMenu = null">Terug naar Verkoop</button>

                        <h2>Passing</h2>

                        <div class="subitems-list">
                            <a href="{{ route('filament.app.resources.production.fitting') }}">Overzicht</a>
                            <a href="#"
                                x-on:click.prevent="Livewire.dispatch('open-create-main-dashboard-passing'); $store.mobileSidebar.open = false; $store.sidebar.close();">Passing aanmaken</a>
                        </div>
                    </div>

                    <div x-show="subMenu === 'offertes'"
                        x-transition:enter="transition-opacity duration-200"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100">
                        <button class="subitems-back" x-on:click="subMenu = null">Terug naar Verkoop</button>

                        <h2>Offertes</h2>

                        <div class="subitems-list">
                            <a href="{{ route('filament.app.resources.quotes.index') }}">Overzicht</a>
                            <a href="{{ route('filament.app.resources.quotes.create') }}">Offerte aanmaken</a>
                        </div>
                    </div>

                    <div x-show="subMenu === 'orders'"
                        x-transition:enter="transition-opacity duration-200"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100">
                        <button class="subitems-back" x-on:click="subMenu = null">Terug naar Verkoop</button>

                        <h2>Orders</h2>

                        <div class="subitems-list">
                            <a href="{{ route('filament.app.resources.orders.index') }}">Overzicht</a>
                            <a href="{{ route('filament.app.resources.orders.create') }}">Order aanmaken</a>
                        </div>
                    </div>
                </div>
            </div>
        </template>
        @endcan

        @can('manage products')
        <template x-if="activeMenu === null || activeMenu === 'producten'">
            <div class="sidebar-menuitem">
                <div class="sidebar-menuitem-wrapper" x-show="activeMenu === null" x-on:click="activeMenu = 'producten'">
                    <div class="sidebar-menuitem-info">
                        <img class="sidebar-menuitem-icon" src="{{ asset('/img/icons/user-menu/tag-solid.svg') }}" alt="menu-icon">
                        <span class="sidebar-menuitem-name">Artikelen</span>
                    </div>
                </div>

                <div class="menuitem-subitems" x-show="activeMenu === 'producten'"
                    x-transition:enter="transition-opacity duration-200"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100">
                    <button class="subitems-back" :class="{ 'mobielheader-marginbottom-none': subMenu !== null }" x-on:click="activeMenu = null; subMenu = null">Alle categorieën</button>
                    <h2 x-show="!subMenu">Artikelen</h2>

                    <div class="subitems-list" x-show="!subMenu">
                        <a href="{{ route('filament.app.resources.products.index') }}">Artikelen</a>
                    </div>
                </div>
            </div>
        </template>
        @endcan

        @can('manage financials')
        <template x-if="activeMenu === null || activeMenu === 'financieel'">
            <div class="sidebar-menuitem">
                <div class="sidebar-menuitem-wrapper" x-show="activeMenu === null" x-on:click="activeMenu = 'financieel'">
                    <div class="sidebar-menuitem-info">
                        <img class="sidebar-menuitem-icon" src="{{ asset('/img/icons/user-menu/receipt-solid.svg') }}" alt="menu-icon">
                        <span class="sidebar-menuitem-name">Financieel</span>
                    </div>
                </div>

                <div class="menuitem-subitems" x-show="activeMenu === 'financieel'"
                    x-transition:enter="transition-opacity duration-200"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100">
                    <button class="subitems-back" :class="{ 'mobielheader-marginbottom-none': subMenu !== null }" x-on:click="activeMenu = null; subMenu = null">Alle categorieën</button>
                    <h2 x-show="!subMenu">Financieel</h2>

                    <div class="subitems-list" x-show="!subMenu">
                        <div x-on:click="subMenu = 'facturen-menu'">Facturen</div>
                        <div x-on:click="subMenu = 'abonnementen-menu'">Abonnementen</div>
                        <a href="{{ route('filament.app.resources.credit-invoices.index') }}">Creditfacturen</a>
                    </div>

                    <div x-show="subMenu === 'facturen-menu'"
                        x-transition:enter="transition-opacity duration-200"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100">
                        <button class="subitems-back" x-on:click="subMenu = null">Terug naar Financieel</button>

                        <h2>Facturen</h2>

                        <div class="subitems-list">
                            <a href="{{ route('filament.app.resources.invoices.index') }}">Overzicht</a>
                            <a href="{{ route('filament.app.resources.invoices.create') }}">Factuur aanmaken</a>
                        </div>
                    </div>

                    <div x-show="subMenu === 'abonnementen-menu'"
                        x-transition:enter="transition-opacity duration-200"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100">
                        <button class="subitems-back" x-on:click="subMenu = null">Terug naar Financieel</button>

                        <h2>Abonnementen</h2>

                        <div class="subitems-list">
                            <a href="{{ route('filament.app.resources.recurring-invoices.index') }}">Overzicht</a>
                            <a href="{{ route('filament.app.resources.recurring-invoices.create') }}">Abonnement aanmaken</a>
                        </div>
                    </div>
                </div>
            </div>
        </template>
        @endcan

        @can('manage reporting')
        <template x-if="activeMenu === null || activeMenu === 'reporting'">
            <div class="sidebar-menuitem">
                <div class="sidebar-menuitem-wrapper" x-show="activeMenu === null" x-on:click="activeMenu = 'reporting'">
                    <div class="sidebar-menuitem-info">
                        <img class="sidebar-menuitem-icon" src="{{ asset('/img/icons/user-menu/square-poll-vertical-solid.svg') }}" alt="menu-icon">
                        <span class="sidebar-menuitem-name">Reporting</span>
                    </div>
                </div>

                <div class="menuitem-subitems" x-show="activeMenu === 'reporting'"
                    x-transition:enter="transition-opacity duration-200"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100">
                    <button class="subitems-back" x-on:click="activeMenu = null">Alle categorieën</button>

                    <h2>Reporting</h2>

                    <div class="subitems-list">
                        @include('filament.partials.reporting-submenu-mobile-links')
                    </div>
                </div>
            </div>
        </template>
        @endcan

        @if ($showAdminMenu)
        <template x-if="activeMenu === null || activeMenu === 'admin'">
            <div class="sidebar-menuitem">
                <div class="sidebar-menuitem-wrapper" x-show="activeMenu === null" x-on:click="activeMenu = 'admin'">
                    <div class="sidebar-menuitem-info">
                        <img class="sidebar-menuitem-icon" src="{{ asset('/img/icons/user-menu/hammer-solid.svg') }}" alt="menu-icon">
                        <span class="sidebar-menuitem-name">Admin</span>
                    </div>
                </div>

                <div class="menuitem-subitems" x-show="activeMenu === 'admin'"
                    x-transition:enter="transition-opacity duration-200"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100">
                    <button class="subitems-back" :class="{ 'mobielheader-marginbottom-none': subMenu !== null }" x-on:click="activeMenu = null; subMenu = null">Alle categorieën</button>
                    <h2 x-show="!subMenu">Admin</h2>

                    <div class="subitems-list" x-show="!subMenu">
                        @can('manage users')
                        <a href="{{ route('filament.app.resources.manager.index') }}">Gebruikers</a>
                        <div x-on:click="subMenu = 'rollen-permissies-menu'">Rollen & Permissies</div>
                        @endcan
                        @can('manage settings')
                        <a href="{{ route('filament.app.resources.customers.settings') }}">Stamgegevens</a>
                        <div x-on:click="subMenu = 'emails-menu'">E-mails</div>
                        @endcan
                    </div>

                    <div x-show="subMenu === 'rollen-permissies-menu'"
                        x-transition:enter="transition-opacity duration-200"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100">
                        <button class="subitems-back" x-on:click="subMenu = null">Terug naar Admin</button>

                        <h2>Rollen & Permissies</h2>

                        @can('manage settings')
                        <div class="subitems-list">
                            <a href="{{ route('filament.app.resources.roles.index') }}">Rollen</a>
                            <a href="{{ route('filament.app.resources.permissions.index') }}">Permissies</a>
                        </div>
                        @endcan
                    </div>

                    <div x-show="subMenu === 'emails-menu'"
                        x-transition:enter="transition-opacity duration-200"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100">
                        <button class="subitems-back" x-on:click="subMenu = null">Terug naar Admin</button>

                        <h2>E-mails</h2>

                        @can('manage settings')
                        <div class="subitems-list">
                            <a href="{{ route('filament.app.resources.email-templates.index') }}">Overzicht</a>
                            <a href="{{ route('filament.app.resources.mail-logs.index') }}">Logs</a>
                        </div>
                        @endcan
                    </div>
                </div>
            </div>
        </template>
        @endif

        <div style="height: 50px; width: 100%; background-color: white;"></div>
    </div>
</div>