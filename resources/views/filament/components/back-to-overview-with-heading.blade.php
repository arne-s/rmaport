@props(['title', 'url', 'class' => ''])
<div class="companyBreadcrumbs {{ $class }}">
    <div class="header">
        <div class="breadcrumb">
            <div class="backTo">
                <a href="{{ $url }}">
                    @svgImg('img/icons/chevron-left.svg')
                    <span>Terug naar {{ $title }}</span>
                </a>
            </div>
        </div>

        @if (method_exists($this, 'getCustomerHeadingDisplayName'))
            <div class="title-container">
                <div class="title-container__title-group">
                    <h2 class="title">
                        <span class="customer-title-label">Klantnaam:</span>
                        <span class="customer-title-name">{{ $this->getCustomerHeadingDisplayName() }}</span>
                    </h2>
                </div>
            </div>
        @elseif (method_exists($this, 'getProductHeadingName'))
            <div class="title-container">
                <div class="title-container__title-group">
                    <h2 class="title">
                        <span class="customer-title-label">Artikel:</span>
                        <span class="customer-title-name">{{ $this->getProductHeadingName() }}</span>
                    </h2>
                </div>
            </div>
        @elseif (method_exists($this, 'getSupplierHeadingName'))
            <div class="title-container">
                <div class="title-container__title-group">
                    <h2 class="title">
                        <span class="customer-title-label">Leverancier:</span>
                        <span class="customer-title-name">{{ $this->getSupplierHeadingName() }}</span>
                    </h2>
                </div>
            </div>
        @elseif (method_exists($this, 'getManagerCreateHeading'))
            <div class="title-container">
                <div class="title-container__title-group">
                    <h2 class="title">{{ $this->getManagerCreateHeading() }}</h2>
                </div>
            </div>
        @elseif (method_exists($this, 'getManagerHeadingDisplayName'))
            <div class="title-container">
                <div class="title-container__title-group">
                    <h2 class="title">
                        <span class="customer-title-label">Gebruiker:</span>
                        <span class="customer-title-name">{{ $this->getManagerHeadingDisplayName() }}</span>
                    </h2>
                </div>
            </div>
        @elseif (
            method_exists($this, 'getHeading')
            && ! ($this instanceof \App\Filament\Resources\CustomerResource\Pages\CreateCustomer)
            && ! ($this instanceof \App\Filament\Resources\ProductResource\Pages\CreateProduct)
            && ! ($this instanceof \App\Filament\Resources\SupplierResource\Pages\CreateSupplier)
            && ! ($this instanceof \App\Filament\Resources\RoleResource\Pages\CreateRole)
            && ! ($this instanceof \App\Filament\Resources\ManagerResource\Pages\CreateManager)
        )
            <div class="title-container">
                <div class="title-container__title-group">
                    <h2 class="title" style="font-size: 16px; font-weight: 700">{{ $this->getHeading() }}</h2>
                </div>
            </div>
        @endif
    </div>
</div>
