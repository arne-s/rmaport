<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Locked;

class ActivateAccount extends SimplePage
{
    protected static bool $isDiscovered = false;

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return false;
    }

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    #[Locked]
    public ?User $newUser = null;

    public bool $invalidToken = false;

    public function mount(string $activationToken): void
    {
        $this->newUser = User::query()
            ->where('activation_token', $activationToken)
            ->first();

        if ($this->newUser === null) {
            $this->invalidToken = true;

            return;
        }

        $this->form->fill([
            'email' => $this->newUser->getEmail(),
        ]);
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Account activeren';
    }

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->invalidToken) {
            return 'Deze activatielink is ongeldig of al gebruikt.';
        }

        return 'Kies een wachtwoord om in te loggen.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraBodyAttributes(): array
    {
        return [
            'class' => 'filament-login-page',
        ];
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        if ($this->invalidToken) {
            return $schema
                ->components([
                    Actions::make([
                        Action::make('login')
                            ->label('Naar inloggen')
                            ->url(route('filament.app.auth.login'))
                            ->extraAttributes(['class' => 'w-full']),
                    ])
                        ->alignment(Alignment::Center)
                        ->fullWidth(),
                ]);
        }

        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('submit')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->fullWidth($this->hasFullWidthFormActions())
                    ->key('form-actions'),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('submit')
                ->label('Activeren')
                ->submit('submit'),
        ];
    }

    protected function hasFullWidthFormActions(): bool
    {
        return true;
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('E-mailadres')
            ->email()
            ->disabled()
            ->dehydrated(false)
            ->placeholder('E-mailadres')
            ->extraInputAttributes(['tabindex' => 1])
            ->extraFieldWrapperAttributes(['class' => 'hideLabel']);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label('Wachtwoord')
            ->password()
            ->placeholder('Wachtwoord')
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('new-password')
            ->required()
            ->minLength(8)
            ->same('passwordConfirmation')
            ->extraInputAttributes(['tabindex' => 2, 'class' => 'filament-login-password-input'])
            ->extraFieldWrapperAttributes(['class' => 'hideLabel']);
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('passwordConfirmation')
            ->label('Wachtwoord bevestigen')
            ->password()
            ->placeholder('Wachtwoord bevestigen')
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('new-password')
            ->required()
            ->extraInputAttributes(['tabindex' => 3, 'class' => 'filament-login-password-input'])
            ->extraFieldWrapperAttributes(['class' => 'hideLabel']);
    }

    public function submit(): void
    {
        if ($this->newUser === null || $this->invalidToken) {
            return;
        }

        $state = $this->form->getState();

        $this->newUser->setPassword(Hash::make($state['password']));
        $this->newUser->setActivatedAt(now()->toDateTimeString());
        $this->newUser->setActivationToken(null);

        throw_unless($this->newUser->save(), Exception::class);

        session()->flash('status', 'Account geactiveerd. Je kunt nu inloggen.');
        session()->flash('login_email', $this->newUser->getEmail());

        $this->redirect(route('filament.app.auth.login'), navigate: false);
    }
}
