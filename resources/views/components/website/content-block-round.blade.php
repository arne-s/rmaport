@props(['page', 'field' => 'contentBlockRound', 'imageAlign' => 'right'])

@php
    $block = $page->content[$field];
    $imageAlign = $block['imageAlign'] ?? $imageAlign;
@endphp
<section {{
    $attributes
        ->class([
            'leftContentRightImageContainer' => $imageAlign === 'right',
            'quoteContainer' => $imageAlign === 'left',
            'pageWidth',
        ])
        ->merge()
    }}
>
    <div class="leftContentRightImageInner">
        @if ($imageAlign === 'left')
            <div class="imageRound">
                <div class="image circleImage">
                    <img src="{{ $page->getFirstMediaUrl($block['image_collection_id']) }}">
                </div>
            </div>
        @endif

        <div class="content">
            <h3>{{ $block['title'] }}</h3>

            {!! $block['content'] !!}

            @if((isset($block['ctaLink1']) && isset($block['ctaTitle1'])) || (isset($block['ctaLink2']) && isset($block['ctaTitle2'])))
                <div class="ctaHeader">
                    @isset($block['ctaLink1'], $block['ctaTitle1'])
                        <a href="{{ $block['ctaLink1'] }}" class="buttonSolid">
                            {{ $block['ctaTitle1'] }}
                        </a>
                    @endisset

                    @isset($block['ctaLink2'], $block['ctaTitle2'])
                        <a href="{{ $block['ctaLink2'] }}" class="buttonOutline">
                            {{ $block['ctaTitle2'] }}
                        </a>
                    @endisset
                </div>
            @endif
        </div>

        @if ($imageAlign === 'right')
            <div class="imageRound">
                <div class="image">
                    <img src="{{ $page->getFirstMediaUrl($block['image_collection_id']) }}">
                </div>
            </div>
        @endif
    </div>

</section>
