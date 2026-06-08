@extends('layouts.quote-approval')

@section('title', config('app.name'))

@section('content')
    <div class="flex flex-col gap-5 text-sm">
        <div class="rounded-lg border-amber-200 bg-amber-50 p-4 dark:bg-amber-950/40">
            <p class="m-0 text-center text-sm font-medium text-amber-950 dark:text-amber-100">
                De geldigheidsduur van deze offerte is verstreken. Je kunt deze offerte niet meer online bevestigen.
            </p>
            @if ($expiresAt !== null)
                <p class="mb-0 mt-2 text-center text-xs text-amber-900/80 dark:text-amber-200/80">
                    Geldig tot {{ $expiresAt->timezone(config('app.timezone'))->format('d-m-Y') }}.
                </p>
            @endif
        </div>
        <p class="m-0 text-center text-sm text-gray-600 dark:text-gray-400">
            Neem contact met ons op voor een nieuwe offerte.
        </p>
    </div>
@endsection
