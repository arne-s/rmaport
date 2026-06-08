<x-filament-widgets::widget class="filament-support-widget">
    <x-filament::card>
        @php
        $user = \Filament\Facades\Filament::auth()->user();
        @endphp

        <div class="h-12 flex items-center space-x-4 rtl:space-x-reverse support-widget__mobile">
            <div class="w-10 h-10 rounded-full bg-gray-200 bg-cover bg-center"
                style="background-image: url({{ asset('img/icons/question.png') }})"
            ></div>
            <div>
                <h2 class="text-lg sm:text-xl font-bold tracking-tight">
                   Support nodig?
                </h2>
                <div class="sm:text-sm text-gray-600 content">
                  Mail <a href="mailto:support@dunico.nl" class="underline">support@dunico.nl</a>
                    of bel <a href="tel:+31237515182" class="underline phoneSupportText">023 751 51 82</a>.
                </div>
            </div>
        </div>
    </x-filament::card>
</x-filament::widget>
