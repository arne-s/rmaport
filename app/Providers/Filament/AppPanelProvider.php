<?php

namespace App\Providers\Filament;

use App\Filament\Notifications\DatabaseNotifications;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Filament\Auth\App2fa;
use App\Filament\Pages\Auth\ActivateAccount;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\PasswordReset\RequestPasswordReset;
use App\Filament\Pages\Auth\PasswordReset\ResetPassword;
use App\Filament\Pages\Auth\EditProfile;
use App\Http\Controllers\Filament\ProfilePendingAvatarPreviewController;
use App\Http\Middleware\Use2faWhenRequired;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Althinect\FilamentSpatieRolesPermissions\FilamentSpatieRolesPermissionsPlugin;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->domain(config('filament.domain'))
            ->brandLogo('/img/logo-small.png')
            ->colors([
                'primary' => '#3366cc',
            ])
            ->darkMode(false)
            ->databaseNotifications(livewireComponent: DatabaseNotifications::class, isLazy: false)
            ->unsavedChangesAlerts()
            ->login(Login::class)
            ->routes(function (): void {
                Route::get('/activate/{activationToken}', ActivateAccount::class)
                    ->name('activate.account');
            })
            ->livewireComponents([
                ActivateAccount::class,
            ])
            ->multiFactorAuthentication([
                App2fa::make()
                    ->recoverable()
                    ->brandName(strip_tags((string) config('app.name'))),
            ])
            ->requiresMultiFactorAuthentication(true)
            ->multiFactorAuthenticationRequiredMiddlewareName(Use2faWhenRequired::class)
            ->profile(EditProfile::class)
            ->passwordReset(RequestPasswordReset::class, ResetPassword::class)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->plugin(FilamentSpatieRolesPermissionsPlugin::make())
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->pages([])
            ->userMenuItems([
                'profile' => Action::make('profile')->hidden(),

                Action::make('settings')
                    ->label('Instellingen')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->url(fn () => Filament::getProfileUrl()),
            ])
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->renderHook(
                PanelsRenderHook::FOOTER,
                fn () => view('filament.components.footer')
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): \Illuminate\Contracts\View\View => view('filament.hooks.user-presence-heartbeat')
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): \Illuminate\Contracts\View\View => view('filament.hooks.chat-echo-listener')
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): \Illuminate\Contracts\View\View => view('filament.hooks.exact-sync-toast-listener')
            )
            ->renderHook(
                PanelsRenderHook::SCRIPTS_BEFORE,
                fn (): \Illuminate\Contracts\View\View => view('filament.hooks.appointment-calendar-picker-scripts')
            )
            ->renderHook(
                PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE,
                fn () => new HtmlString('<span class="resetPasswordDescription">Voer je emailadres in en check je mailbox om je wachtwoord te wijzigen. Check ook de spam.</span>')
            )
            ->renderHook(
                PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_AFTER,
                fn () => new HtmlString('<a href="/login" class="backToLogin passwordRequest">Terug naar loginscherm</a>')
            )
            ->renderHook(
                PanelsRenderHook::AUTH_PASSWORD_RESET_RESET_FORM_AFTER,
                fn () => new HtmlString('<a href="/login" class="backToLogin">Terug naar inloggen</a>')
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->authenticatedRoutes(function (Panel $panel): void {
                Route::get('/profile/pending-avatar-preview', ProfilePendingAvatarPreviewController::class)
                    ->name('pending-avatar-preview');
            });
    }
}
