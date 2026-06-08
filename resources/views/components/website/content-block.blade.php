@props(['page', 'block', 'imageAlign' => 'right'])

<section {{ $attributes->merge(['class' => 'homepageContent pageWidth']) }}>
    @if ($imageAlign === 'left')
        <div class="imageBg hideTel">
            <img src="{{ $page->getFirstMediaUrl($block['image_collection_id']) }}">
        </div>
    @endif

    <div
        @class([
            'homepageInnercontent' => $imageAlign === 'right',
            'homepageInnercontentmirror' => $imageAlign === 'left',
        ])
    >
        <div class="leftInnercontent">
            <p class="yellowText">{{ $block['subtitle'] }}</p>

            <h2>{{ $block['title'] }}</h2>

            <div class="imageBg hideDesk">
                <img src="{{ $page->getFirstMediaUrl($block['image_collection_id']) }}">
            </div>

            <p class="homepageText">
                {!! $block['content'] !!}
            </p>

            @isset($block['usp1'])
                <ul>
                    @isset($block['usp1'])
                        <li>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d0e1fe" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            {!! $block['usp1'] !!}
                        </li>
                    @endisset
                    @isset($block['usp2'])
                        <li>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d0e1fe" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            {!! $block['usp2'] !!}
                        </li>
                    @endisset
                    @isset($block['usp3'])
                        <li>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d0e1fe" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            {!! $block['usp3'] !!}
                        </li>
                    @endisset
                </ul>
            @endisset

            @if(isset($block['ctaLink']) && isset($block['ctaTitle']))
                <a href="{{ $block['ctaLink'] }}">
                    {{ $block['ctaTitle'] }}
                </a>
            @endif
        </div>
    </div>

    @if ($imageAlign === 'right')
        <div class="imageBg hideTel">
            <img src="{{ $page->getFirstMediaUrl($block['image_collection_id']) }}">
        </div>
    @endif
</section>
