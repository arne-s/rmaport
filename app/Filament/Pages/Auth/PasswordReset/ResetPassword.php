<?php

namespace App\Filament\Pages\Auth\PasswordReset;

use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;

class ResetPassword extends BaseResetPassword
{
    public function loginAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('login')
            ->label('Terug naar loginscherm')
            ->extraAttributes(['class' => 'hidden'])
            ->url('/login');
    }

    public function getExtraBodyAttributes(): array
    {
        return array_merge(parent::getExtraBodyAttributes(), ['class' => 'filament-login-page']);
    }
}
