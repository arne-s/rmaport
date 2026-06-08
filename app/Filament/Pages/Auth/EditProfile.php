<?php

namespace App\Filament\Pages\Auth;

use App\Http\Livewire\ProfileAvatarUpload;
use App\Models\Role;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire as FilamentLivewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\TextInput;

/**
 * @property-read Schema $form
 */

class EditProfile extends BaseEditProfile
{
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function mount(): void
    {
        session()->forget(ProfileAvatarUpload::AVATAR_REMOVAL_PENDING_SESSION_KEY);
        ProfileAvatarUpload::clearPendingUploadFromSession(deleteFile: true);

        parent::mount();
    }

    protected function afterSave(): void
    {
        $user = $this->getUser();

        ProfileAvatarUpload::commitPendingUploadToUser($user);

        $pendingFor = session()->pull(ProfileAvatarUpload::AVATAR_REMOVAL_PENDING_SESSION_KEY);

        if ($pendingFor !== null && (int) $pendingFor === (int) $user->getKey()) {
            $user->clearMediaCollection('avatar');
        }
    }

    public static function isSimple(): bool
    {
        return false;
    }

    public static function getLabel(): string // adjust label in navigation
    {
        $user = auth()->user();
        if ($user) {
            return $user->getNameAttribute();
        } else {
            return 'Profiel';
        }
    }

    public function getPageClasses(): array
    {
        return ['page-edit-profile'];
    }

    public function getBreadcrumbs(): array
    {
        return [
            '/beheer' => 'Admin',
            '' => 'Mijn profiel',
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Dashboard',
                        'url' => route('filament.app.pages.dashboard'),
                    ]),

                Section::make('Mijn accountgegevens')
                    ->extraAttributes(['class' => 'settingspage-profile-section'])
                    ->columns(1)
                    ->schema([
                        TextInput::make('first_name')
                            ->label('Voornaam')
                            ->required()
                            ->maxLength(255)
                            ->autofocus(),
                        TextInput::make('last_name')
                            ->label('Achternaam')
                            ->required()
                            ->maxLength(255),
                        $this->getEmailFormComponent(),
                        $this->getRoleFormComponent(),
                        $this->getAvatarFormComponent(),
                    ]),

                Section::make()
                    ->columns(1)
                    ->extraAttributes(['class' => 'settingspage-password-section custom-form-design'])
                    ->schema([
                        $this->getCurrentPasswordFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ]),

            ]);
    }

    protected function getAvatarFormComponent(): Component
    {
        return FilamentLivewire::make(ProfileAvatarUpload::class)
            ->key('profile-avatar-upload');
    }

    protected function getRoleFormComponent(): Component
    {
        return TextInput::make('role_display')
            ->label('Rol')
            ->disabled()
            ->dehydrated(false)
            ->formatStateUsing(fn (): string => $this->getUser()->roles
                ->sortBy(fn (Role $role): string => $role->getDisplayName())
                ->map(fn (Role $role): string => $role->getDisplayName())
                ->values()
                ->join(', '));
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()
            ->label('Huidig wachtwoord')
            ->validationAttribute('huidig wachtwoord')
            ->visible(true)
            ->required(fn(Get $get): bool => filled($get('password')) || ($get('email') !== $this->getUser()->getAttributeValue('email')));
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->label('Nieuw wachtwoord')
            ->validationAttribute('nieuw wachtwoord')
            ->placeholder('Laat leeg om niet te wijzigen');
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return parent::getPasswordConfirmationFormComponent()
            ->label('Nieuw wachtwoord bevestigen')
            ->validationAttribute('nieuw wachtwoord bevestigen')
            ->visible(true)
            ->required(fn(Get $get): bool => filled($get('password')))
            ->placeholder('Laat leeg om niet te wijzigen');
    }
}
