@props(['page', 'field' => 'newsItems'])

@php
    use App\Models\News;
    use Illuminate\Support\Str;

    $content = $page->content[$field];
@endphp
@isset ($content['title'])
    <section {{ $attributes->merge(['class' => 'newsSection pageWidth']) }}>
        <h2>{{ $content['title'] }}</h2>

        @php
            $newsType = $content['news_type'];
            $articles = News::when(isset($newsType), function ($query) use ($newsType) {
                return $query->where('news_type_id', $newsType);
            })
                ->where('enabled', true)
                ->orderBy('created_at', 'desc')
                ->limit(3)
                ->get();
        @endphp
        <div class="newsBlock">
            @if (count($articles) > 0)
                @foreach ($articles as $item)
                    <a href="/nieuws/{{ $item->slug }}">
                        <img src="{{ $item->getFirstMediaUrl('featured_image') }}">

                        <div class="contentNieuwsItem">
                            <p>{{ $item->title }}</p>

                            <p class="newsSubText">
                                @if (!empty($item->intro_text))
                                    {{ Str::limit($item->intro_text, 130) }}
                                @else
                                    {{ Str::limit($item->content, 130) }}
                                @endif
                            </p>

                            <span class="linkNieuws">Lees meer...</span>
                        </div>
                    </a>
                @endforeach
            @endif
        </div>
    </section>
@endisset
