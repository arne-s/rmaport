@props(['page', 'field' => 'usps'])

@php
    $content = $page->content[$field];
@endphp
@if (isset($content['title']) && isset($content['usps']) && count($content['usps']) > 0)
    <section {{ $attributes->merge(['class' => 'supportSection pageWidth']) }}>
        <h2>{{ $content['title'] }}</h2>

        <div class="supportContent">
            @foreach ($content['usps'] as $usp)
                <div class="supportInnercontent">
                    <img src="{{ $page->getFirstMediaUrl($usp['image_collection_id']) }}">

                    <div class="supportInnertext">
                        <p class="supportTitle">{{ $usp['title'] }}</p>

                        {!! $usp['content'] !!}
                    </div>

                    <a href="{{ $usp['ctaLink'] }}">
                        {{ $usp['ctaTitle'] }}
                    </a>
                </div>
            @endforeach
        </div>
    </section>
@endif
