<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View|RedirectResponse
    {
        $subdomain = explode('.', request()->getHost())[0];
        if ($subdomain === 'beheer') {
            return redirect()->route('filament.app.auth.login');
        }

        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     * @throws ValidationException
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $rememberMe = $request->boolean('remember');

        $request->authenticate();

        $request->session()->regenerate();

        // Store flag in session to track if remember me was used
        $request->session()->put('remember_me_used', $rememberMe);
        // Store login timestamp to detect stale sessions (browser close/reopen)
        $request->session()->put('login_timestamp', time());

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
