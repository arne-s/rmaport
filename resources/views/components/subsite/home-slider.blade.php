@php
    /** @var $slider \App\Models\CompanySlider  */
    /** @var $user \App\Models\User  */
    $slider = $user->company?->slider;
@endphp
<div class="home-slider">
    <div class="nav">
        <x-arrow-button class="prev up color-secondary" onclick="homeSlider.slidePrev(); return false"/>
        <x-arrow-button class="next down" onclick="homeSlider.slideNext(); return false"/>
    </div>
    @if (!empty($slider->media))
        <div class="home-slider-element home-products">
            <div class="swiper-wrapper relative">
                @foreach ($slider->media->sortByDesc('order_column') as $slide)
                    <div class="swiper-slide" style="background-image: url('{{ $slide->getUrl('full') }}')"></div>
                @endforeach
            </div>
            <div class="content" style="position: absolute; top: 0;">
                <div class="main">
                    <h3>{{ $slider->getSubtitle() }}</h3>
                    <h1>{{ $slider->getSlogan() }}</h1>
                    <x-secondary-button onclick="Livewire.dispatch('openModal', { component: 'modals.category-selector' })"
                                        class="lg has-icon homePageBtn">
                        <span>Ontdek ons assortiment</span>
                        <img src="/img/icons/right-arrow.png" alt=""/>
                    </x-secondary-button>
                </div>
            </div>
        </div>
    @endif
</div>
