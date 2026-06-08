@props(['page', 'field' => 'usps'])

@php
    $content = $page->content[$field];
@endphp
@if (isset($content['title']) && isset($content['usps']) && count($content['usps']) > 0)
    <section {{ $attributes->merge(['class' => 'cultuurSection pageWidth']) }}>
        <h2>{{ $content['title'] }}</h2>

        <div class="cultuurSubtext">
            {!! $content['content'] !!}
</div>

        <div class="uspBox">
            @foreach ($content['usps'] as $usp)
                <div class="uspBoxcontent">
                    <img src="{{ $page->getFirstMediaUrl($usp['image_collection_id']) }}">

                    <div class="upsText">
                        <p class="uspTitle">{{ $usp['title'] }}</p>

                        <p class="uspSubtext">
                            {!! $usp['content'] !!}
                        </p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif
