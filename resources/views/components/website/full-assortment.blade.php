@props(['page', 'field' => 'fullAssortment'])

@php
    $content = $page->content[$field];
@endphp
@if (isset($content['title']) && isset($content['content']) && count($content['content']) > 0)
    <section {{ $attributes->merge(['class' => 'uitgebreideAssortiment pageWidth']) }}>
        <h2>{{ $content['title'] }}</h2>

        <div class="uitgebreideAssortimentContainer">
            @foreach ($content['content'] as $category)
                <div class="assortimentContent">
                    <div class="assortimentImage">
                        @if ($category['showImageBackground'])
                            <div class="assortimentImageBg"></div>
                        @endif

                        <img
                            src="{{ $page->getFirstMediaUrl($category['image_collection_id']) }}"
                            @class(['onderdelen' => !$category['showImageBackground']])
                        >
                    </div>

                    <p>{{ $category['category']}}</p>

                    <ul>
                        @foreach ($category['subcategories'] as $subcategory)
                            <li>
                                <div class="bulletPoint"></div>
                                {{ $subcategory['name'] }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </section>
@endif
