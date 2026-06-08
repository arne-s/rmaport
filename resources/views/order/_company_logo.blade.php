@php
    /** @var App\Models\Customer $company */
    /** @var Spatie\MediaLibrary\MediaCollections\Models\Media $logo */
        $logo = $company?->getMedia('logo')->first();
        $url = $logo?->getUrl('order');
@endphp

<div class="company-logo" style="padding-bottom: 70px; padding-top: 20px;">
    @if (!empty($logo))
        <img src="{{$url}}" alt=""/>
    @endif
</div>

