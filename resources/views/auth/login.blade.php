<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    @section('title', __('auth.login.title'))

    @error('suspended')
    <div class="suspended mb-5 text-center text-base">
        Je kunt op dit moment niet bestellen.
    </div>
    @enderror

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Email Address -->
        <div>
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required
                          autofocus autocomplete="username" placeholder="E-mailadres"/>
            <x-input-error :messages="$errors->get('email')" class="mt-2"/>
        </div>

        <!-- Password -->
        <div class="mt-2 relative">
            <x-text-input id="password" class="block mt-1 w-full pr-10"
                          type="password"
                          name="password"
                          required autocomplete="current-password"
                          placeholder="Wachtwoord"/>
            <button type="button" id="togglePassword" tabindex="-1" class="eye absolute right-2 transform -translate-y-1/2 text-gray-400 focus:outline-hidden">
                <!-- Heroicons eye/eye-off -->
                <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
            </button>
            <x-input-error :messages="$errors->get('password')" class="mt-2"/>
        </div>

        <!-- Remember Me -->
        <div class="flex items-center justify-between mt-0.5">
            <label for="remember_me" class="inline-flex align-left">
                <input id="remember_me" type="checkbox"
                       class="rounded-sm border-gray-300 text-indigo-600 shadow-xs focus:ring-indigo-500" name="remember">
                <span class="ml-2 text-[13px] text-gray-600">{{ __('auth.login.remember_me') }}</span>
            </label>

            @if (Route::has('password.request'))
                <a class="forgotPassword underline text-[13px] text-gray-600 hover:text-gray-900 rounded-md focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                   href="{{ route('password.request') }}">
                    {{ __('auth.login.forgot_password') }}
                </a>
            @endif
        </div>


        <div class="mt-4">
            <x-primary-button class="w-full text-white text-center text-sm">
                {{ __('auth.login.log_in') }}
            </x-primary-button>

            <div class="flex items-center text-gray-500 text-sm pt-[21px] pb-5">
                <span class="flex-1 border-t border-gray-300"></span>
                <span class="px-3">of</span>
                <span class="flex-1 border-t border-gray-300"></span>
            </div>
        </div>
    </form>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const passwordInput = document.getElementById('password');
            const togglePassword = document.getElementById('togglePassword');
            const eyeIcon = document.getElementById('eyeIcon');
            let visible = false;
            togglePassword.addEventListener('click', function () {
                visible = !visible;
                passwordInput.type = visible ? 'text' : 'password';
                eyeIcon.innerHTML = visible
                    ? `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.477 0-8.268-2.943-9.542-7a9.956 9.956 0 012.042-3.292m3.087-2.727A9.956 9.956 0 0112 5c4.477 0 8.268 2.943 9.542 7a9.956 9.956 0 01-4.043 5.197M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 18" />`
                    : `<path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M15 12a3 3 0 11-6 0 3 3 0 016 0z\" />\n<path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z\" />`;
            });
        });
    </script>
    @endpush
</x-guest-layout>
