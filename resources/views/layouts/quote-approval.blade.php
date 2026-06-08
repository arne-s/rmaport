<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="application-name" content="{{ config('app.name') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">

    <title>@yield('title', config('app.name'))</title>

    <style>
        [x-cloak] { display: none !important; }
        .quote-approval-page .fi-simple-layout {
            background-color: #fff;
            background-image: url('{{ asset('img/bg-circles.svg') }}');
            background-position: center;
            background-attachment: fixed;
        }
        @media (max-width: 992px) {
            .quote-approval-page .fi-simple-layout {
                padding: 10px;
            }
        }
        .quote-approval-page .quote-approval-main {
            padding: 1.375rem 1.375rem 1.375rem;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.21);
            background: #fff;
        }
        .dark .quote-approval-page .quote-approval-main {
            background: rgb(17 24 39);
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.45);
        }
        /* Match Filament login header logo (admin.scss .filament-login-page header.fi-simple-header img) */
        .quote-approval-page .fi-simple-header img.fi-logo {
            background-color: #fff;
            margin-top: -36px;
            border-radius: 100% 100% 0 0;
            padding: 17px 15px;
            width: 182px;
            height: 76px !important;
            object-fit: contain;
            margin-bottom: 0;
        }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @filamentStyles
    @stack('head')
</head>
<body class="fi-body antialiased text-gray-950 dark:text-white quote-approval-page">
<div class="fi-simple-layout flex min-h-screen flex-col items-center justify-center px-4 py-10 sm:py-12 bg-gray-50 dark:bg-gray-950">
    <main class="fi-simple-main fi-width-lg w-full max-w-2xl quote-approval-main">
        <div class="fi-simple-page">
            <div class="fi-simple-page-content flex flex-col gap-2">
                <header class="fi-simple-header flex flex-col items-center text-center gap-1">
                    <img
                        src="{{ asset('img/logo.svg') }}"
                        alt="{{ config('app.name') }} logo"
                        class="fi-logo"
                    >
                </header>

                @yield('content')
            </div>
        </div>
    </main>
</div>
@filamentScripts
@stack('scripts')
</body>
</html>
