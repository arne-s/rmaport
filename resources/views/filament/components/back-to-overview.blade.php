@props(['title', 'url', 'class' => '', 'pageTitle' => null, 'livewireBackMethod' => null])
<div class="companyBreadcrumbs {{ $class }}">
    <div class="header">
        <div class="breadcrumb">
            <div class="backTo">
                @if (filled($livewireBackMethod))
                    <a href="{{ $url }}" wire:click.prevent="{{ $livewireBackMethod }}">
                        @svgImg('img/icons/chevron-left.svg')
                        <span>Terug naar {{ $title }}</span>
                    </a>
                @else
                    <a href="{{ $url }}">
                        @svgImg('img/icons/chevron-left.svg')
                        <span>Terug naar {{ $title }}</span>
                    </a>
                @endif
            </div>
        </div>

        @if (filled($pageTitle))
            <div class="title-container">
                <div class="title-container__title-group">
                    <h2 class="title">{{ $pageTitle }}</h2>
                </div>
            </div>
        @endif
    </div>
</div>