@props(['page', 'field' => 'brands'])

@php
    $content = $page->content[$field];
@endphp
<section {{ $attributes->merge(['class' => 'assortment pageWidth']) }}>
    <div class="assortimentContent">
        <h2 class="hideDesk">{{ $content['title'] }}</h2>

        <ul>
            <li>
                <img src="/img/website/buiten-zonwering.png" alt="">
            </li>
            <li>
                <img src="/img/website/binnen-zonwering.png" alt="">
            </li>
            <li>
                <img src="/img/website/onderdelen.png" alt="">
            </li>
        </ul>

        <div class="assortimentcontentRight">
            <h2 class="hideTel">{{ $content['title'] }}</h2>

            <a href="{{ $content['ctaLink'] }}">
                {{ $content['ctaTitle'] }}
            </a>
        </div>
    </div>
</section>
