@props(['page', 'field' => 'header', 'newsArticle'])

@php
    $content = $page->content[$field] ?? null;
@endphp
@if (isset($content['title']) || isset($newsArticle))
    <section {{ $attributes->merge(['class' => 'headerContainer headerWidth']) }}>
        <div class="headerInner yellowBg">
            <div class="content">
                @isset ($newsArticle)
                    <span class="categoryNewsTop">{{ $newsArticle->newsType?->name }}</span>

                    <h1>{{ $newsArticle->title }}</h1>

                    <p>{{ $newsArticle->subtitle }}</p>
                @else
                    <h1>{{ $content['title'] }}</h1>

                    @isset ($content['subtitle'])
                        <p>{{ $content['subtitle'] }}</p>
                    @endisset
                @endisset

                @if ((isset($content['ctaTitle1']) && isset($content['ctaLink1'])) || (isset($content['ctaTitle2']) && isset($content['ctaLink1'])))
                    <div class="ctaHeader">
                        @if ($content['ctaTitle1'] && $content['ctaLink1'])
                            <a href="{{ $content['ctaLink1'] }}" class="buttonSolid buttonWhite">
                                {{ $content['ctaTitle1'] }}
                            </a>
                        @endif

                        @if ($content['ctaTitle2'] && $content['ctaLink2'])
                            <a href="{{ $content['ctaLink2'] }}" class="buttonOutline buttonWhite">
                                {{ $content['ctaTitle2'] }}
                            </a>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </section>
@endisset
