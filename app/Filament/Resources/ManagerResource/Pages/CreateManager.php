<?php

namespace App\Filament\Resources\ManagerResource\Pages;

use App\Filament\Resources\ManagerResource;
use App\Mail\AccountActivateMail;
use App\Models\Role;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CreateManager extends CreateRecord
{
    protected static string $resource = ManagerResource::class;

    protected static ?string $title = 'Gebruiker aanmaken';

    protected static ?string $breadcrumb = 'Gebruiker aanmaken';

    public function getManagerCreateHeading(): string
    {
        return 'Gebruiker aanmaken';
    }

    public function getHeading(): string
    {
        return 'Gebruiker aanmaken';
    }

    public function getTitle(): string
    {
        return 'Gebruiker aanmaken';
    }

    protected function afterFill(): void
    {
        $raw = $this->form->getRawState();
        if (filled($raw['role_ids'] ?? null)) {
            return;
        }

        $managerId = Role::findByName('manager', 'web')?->getKey();
        if ($managerId === null) {
            return;
        }

        $this->form->fill([
            ...$raw,
            'role_ids' => [$managerId],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['role_ids']);

        $data['password'] = bcrypt(Str::password(16));
        $data['activation_token'] = Str::random(36);
        $data['activated_at'] = null;

        if (! array_key_exists('requires_app_2fa', $data)) {
            $data['requires_app_2fa'] = true;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        $user = $this->record;
        $state = $this->form->getRawState();
        $roles = ManagerResource::resolveWebRolesFromSelectState($state['role_ids'] ?? null);

        if ($roles->isEmpty()) {
            $user->assignRole('manager');
        } else {
            $user->syncRoles($roles);
        }

        if (! $user->can('access filament panel')) {
            $user->assignRole('manager');
        }

        if (! $user->hasPendingActivation()) {
            $user->forceFill([
                'activation_token' => Str::random(36),
                'activated_at' => null,
            ]);
            $user->save();
        }

        $user->refresh();

        Mail::to($user->email)->send(new AccountActivateMail($user));

        Notification::make()
            ->title('Activatiemail verzonden')
            ->body("Er is een activatiemail gestuurd naar {$user->email}.")
            ->success()
            ->send();
    }
}
