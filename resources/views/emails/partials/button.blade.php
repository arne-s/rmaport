@props(['url', 'label', 'backgroundColor', 'textColor', 'fontWeight', 'padding', 'borderRadius', 'fontSize'])

@php
    $backgroundColor = $backgroundColor ?? '#d0e1fe';
    $textColor = $textColor ?? '#333333';
    $fontWeight = $fontWeight ?? '550';
    $padding = $padding ?? '8px 85px';
    $borderRadius = $borderRadius ?? '2px';
    $fontSize = $fontSize ?? '18px';
@endphp

<a
    href="{{ $url }}"
    style="display: inline-block; padding: {{ $padding }}; background-color: {{ $backgroundColor }}; font-size: {{ $fontSize }}; font-weight: {{ $fontWeight }}; color: {{ $textColor }}; text-decoration: none; border-radius: {{ $borderRadius }};"
    target="_blank"
    rel="noopener noreferrer"
>
    {{ $label }}
</a>
