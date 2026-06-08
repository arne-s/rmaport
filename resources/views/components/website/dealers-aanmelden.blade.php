@props(['page', 'field' => 'demo', 'type' => null])

@php
    use App\Helpers\Sanitize;

    $content = $page->content[$field];
@endphp
@isset ($content['title'], $content['ctaTitle'], $content['ctaLink'])
    <div class="footerExtended">
        <section {{
            $attributes
                ->class([
                    'contactDemo' => $type === 'largeImage',
                    'endDemoSection pageWidth footerExtra',
                ])
                ->merge()
        }}
        >
            <div class="endDemoInner imgLeft">
                <div class="demoInnertext">
                    <h2>{{ $content['title'] }}</h2>

                    {!! Sanitize::sanitizeHtml($content['content']) !!}

                    <a href="{{ $content['ctaLink'] }}" class="buttonSolid">
                        {{ $content['ctaTitle'] }}
                    </a>
                </div>

                <img src="{{ $page->getFirstMediaUrl('demo') }}">
        </section>
    </div>
@endisset
