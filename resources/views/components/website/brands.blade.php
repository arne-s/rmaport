@props(['page', 'field' => 'brands'])

@php
    $content = $page->content[$field];
@endphp
@isset ($content)
    <section {{ $attributes->merge(['class' => 'headerSubcontent pageWidth']) }}>
        <div class="subContent">
            {!! $content['content'] !!}

            <div class="imageBrands">
                @foreach ($page->getMedia('brands')->sortByDesc('order_column') as $media)
                    <img src="{{ $media->getUrl() }}">
                @endforeach
            </div>
        </div>
    </section>
@endisset
