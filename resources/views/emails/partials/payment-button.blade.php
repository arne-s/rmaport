@props(['url', 'label', 'backgroundColor', 'textColor', 'fontWeight'])

@php
    $backgroundColor =  $backgroundColor ?? '#d0e1fe';
    $textColor = $textColor ?? '#333333';
    $fontWeight = $fontWeight ?? '550';
@endphp

<a href="{{ $url }}">
    <img src="{{url('/img/invoice/ideal-link.png')}}">
</a>
