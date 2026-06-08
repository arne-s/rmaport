<?php

namespace App\Filament\Pages\Auth\PasswordReset;

use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use LogicException;


class RequestPasswordReset extends BaseRequestPasswordReset
{
    use WithRateLimiting;

    protected string $view = 'filament-panels::auth.password-reset.request-password-reset';

    public ?array $data = [];
    public bool $success = false;

    public function request(): void // extend the form to allow success message on page itself after sending reset link
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();

        $pendingUser = User::query()
            ->where('email', $data['email'] ?? '')
            ->first();

        if ($pendingUser?->hasPendingActivation()) {
            throw ValidationException::withMessages([
                'data.email' => 'Activeer eerst je account via de e-mail die je hebt ontvangen.',
            ]);
        }

        $status = Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword $user, string $token): void {
                if (
                    ($user instanceof FilamentUser) &&
                    (! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel()))
                ) {
                    return;
                }

                if (! method_exists($user, 'notify')) {
                    $userClass = $user::class;

                    throw new LogicException("Model [{$userClass}] does not have a [notify()] method.");
                }

                $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                $notification->url = Filament::getResetPasswordUrl($token, $user);

                $user->notify($notification);

                if (class_exists(PasswordResetLinkSent::class)) {
                    event(new PasswordResetLinkSent($user));
                }
            },
        );

        if ($status !== Password::RESET_LINK_SENT) {
            $this->getFailureNotification($status)?->send();

            return;
        }

        $this->getSentNotification($status)?->send();

        $this->success = true;

        $this->form->fill();
    }

    public function loginAction(): \Filament\Actions\Action // hide back to login link at top - this is loaded in via render hook
    {
        return \Filament\Actions\Action::make('login')
            ->label('Terug naar loginscherm')
            ->extraAttributes(['class' => 'hidden'])
            ->url('/login');
    }


    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/password-reset/request-password-reset.form.email.label'))
            ->email()
            ->required()
            ->autocomplete()
            ->extraFieldWrapperAttributes(['class' => 'hideLabel'])
            ->placeholder('E-mailadres')
            ->autofocus();
    }

    protected function getRequestFormAction(): Action
    {
        return Action::make('request')
            ->label('Verstuur')
            ->submit('request')
            ->extraAttributes(['class' => 'passwordBtn']);
    }

    public function getExtraBodyAttributes(): array
    {
        return array_merge(parent::getExtraBodyAttributes(), ['class' => 'filament-login-page']);
    }
}
