@php
    /** @var App\Models\Customer $customer */
    /** @var Spatie\MediaLibrary\MediaCollections\Models\Media $logo */
@endphp

<div class="company-logo">
    @if ($url)
        <a href="/"><img src="{{$url}}" alt=""/></a>
    @else
        <a href="/"><img src="{{ url('img/placeholder-logo.png') }}" style="max-height: 58px; position: relative; top: -2px; width: auto;" alt=""/></a>
    @endif
</div>
