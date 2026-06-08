@props([
    'title',
    'url',
    'class' => '',
    'breadcrumbs' => [],
])

@php
    $params = request()->method() === 'POST'
        ? request()->headers->get('referer')
        : request()->getQueryString();
@endphp

@if (! str_contains($params ?? '', 'minimal') && $breadcrumbs)
    @teleport('#customBreadcrumbs')
        <x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />
    @endteleport
@endif

@include('filament.components.back-to-overview', [
    'title' => $title,
    'url' => $url,
    'class' => $class,
])
