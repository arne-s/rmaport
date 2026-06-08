{{-- Hardcoded RD Mobility logo: absolute URL so wkhtmltopdf can load it from temp HTML --}}
@php
    $logoUrl = rtrim(config('app.url', 'https://beheer.autovision.nl'), '/') . '/img/logo.svg';
    $align = $align ?? 'left';
    $variant = $variant ?? 'default';

    if ($variant === 'inline-top-right') {
        $wrapperStyle = 'text-align: right; padding-bottom: 0;';
    } elseif ($align === 'right') {
        $wrapperStyle = 'float: right; text-align: right; padding-bottom: 15px; padding-top: 5px;margin-right: 50px';
    } else {
        $wrapperStyle = 'padding-bottom: 60px; padding-top: 20px;';
    }
@endphp
<div class="company-logo" style="{{ $wrapperStyle }}">
    <img src="{{ $logoUrl }}" alt="autovision" style="max-height: 60px; width: auto;">
</div>
