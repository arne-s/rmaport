<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">

    <meta name="application-name" content="{{ config('app.name') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">

    <title>@if(!empty(trim($__env->yieldContent('title'))))
            @yield('title') - {{ config('app.name') }}
        @else
            {{ config('app.name') }}
        @endif</title>

    <style>[x-cloak] {
            display: none !important;
        }</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @filamentStyles
    @filamentScripts
    @stack('scripts')
</head>
<body class="text-gray-900 antialiased layout-auth">
<div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 background-wrapper">
    <div class="inner w-full sm:max-w-xl mt-6 px-6 py-4 overflow-hidden @yield('class')">
        <div class="modal-head">
            @if (!isset($attributes['hideLogo']) || !$attributes['hideLogo'])
                <div class="logoContainer">
                    <div class="logo">
                        <img src="{{ asset('img/logo-small.png') }}" class="mx-auto mb-4" alt="Logo">
                    </div>
                </div>
            @endif
            <h3>@yield('title')</h3>
        </div>

        {{ $slot }}
    </div>
</div>
</body>
</html>
