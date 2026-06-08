<x-guest-layout>
    @section('title', 'Wachtwoord vergeten')

    <x-auth-session-status class="mb-4" :status="session('status')"/>

    @if(session('sent'))
        <p class="mb-4 text-md text-gray-600" style="margin-top: -8px;">
            {!!  __('auth.forgot_password.forgot_password_instructions')  !!}
        </p>
        @else
        <p class="textForgotPass mb-3 text-md text-gray-600 text-center" style="margin-top: -8px;">
            {{ __('auth.forgot_password.forgot_password_intro') }}
        </p>
    @endif


    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        @if(!session('sent'))
        <div>
            <!-- <x-input-label for="email" :value="__('E-mailadres')"/> -->
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required
                          autofocus
                          placeholder="E-mailadres'"/>
            <x-input-error :messages="$errors->get('email')" class="mt-2"/>
        </div>

        <div class="flex items-center justify-end mt-3">
            <x-primary-button>
                {{ __('auth.forgot_password.submit_button_label') }}
            </x-primary-button>
        </div>

        <div class="text-center mt-2 backToLogin">
            <a href="{{ route('login') }}" class="underline font-bold text-xs">Terug naar loginscherm</a>
        </div>
        @else
            <div class="flex items-center justify-end mt-4 pt-4">
                <a href="{{ route('login') }}" class="underline font-bold text-xs">
                    <x-primary-button type="button">
                    {{ __('auth.forgot_password.back_to_login_button_label') }}
                    </x-primary-button>
                </a>
            </div>
        @endif
    </form>
</x-guest-layout>
