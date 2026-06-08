<!DOCTYPE html>

<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="app">
<head>
    <meta charset="utf-8">
    <meta name="application-name" content="{{ config('app.name') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="format-detection" content="telephone=no"/>
    <meta name="viewport"
          content="width=device-width, initial-scale=1.1, maximum-scale=1.0, user-scalable=0">

    <style>[x-cloak] {
            display: none !important;
        }

        section.product-builder div.summary div.summary-wrap div.info div.attributes {
            max-height: 400px;
        }

        @media (max-width: 800px) {
            div.summary div.summary-wrap {
                position: fixed !important;
                bottom: 0 !important;
            }
        }


        .hidden-on-minimal-layout {
            display: none !important;
        }

        /*section.product-builder div.builder div.step {*/
        /*    padding: 20px 0 !important;*/
        /*}*/

        h1.product-title {
            margin-top: 0;
        }

        section.product-builder div.summary {
            top: 0 !important;
        }

        section.product-builder div.summary div.summary-wrap {
            border: 1px solid #e7e7e7;
        }

        section.product-builder hr {
            display: none;
        }

        section.cart {
            display: none !important;
        }
    </style>

    @php
    $company = auth()?->user()?->getCompany() ?? null;
    @endphp

    @if ($company)
        @livewire('design-injector', ['company' => $company])
    @else
        <style>
            *, ::before, ::after {
                --primary-color: #91C020;
                --primary-color-2: #91C020;
                --primary-color-3: #91C020;
                --primary-color-4: #91C020;
                --text-color: #3b3b3b;
                --tw-ring-color: #91C020;
            }
        </style>
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @filamentStyles
    @filamentScripts

    @stack('scripts')
</head>

<body class="antialiased layout-minimal {{$bodyClasses ?? ''}} @yield('body-class')">
<div class="page-wrapper">
    <main>
        {{ $slot }}
    </main>
</div>

{{--@include('popper::assets')--}}
@livewire('wire-elements-modal')
</body>
</html>
