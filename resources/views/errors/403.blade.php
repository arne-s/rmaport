<x-guest-layout>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                @section('title', '403')
                <p class="text-center mb-4 pb-4">U heeft geen toegang tot deze pagina</p>

                @php
                    $subdomain = explode('.', request()->getHost())[0];
                @endphp

                @if ($subdomain === 'beheer')
                    <div class="text-center">
                        <a href="/logout" class="underline">opnieuw inloggen</a>
                    </div>
                @endif
            </div>
        </div>
        <style>
            div.modal-head {
                text-align: center;
                font-size: 24px;
                line-height: 40px;
                font-weight: bold;
            }
        </style>
    </div>
</x-guest-layout>
