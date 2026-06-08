<?php

namespace App\Http\Livewire;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ProfileAvatarUpload extends Component
{
    use WithFileUploads;

    /** @see \App\Filament\Pages\Auth\EditProfile::afterSave() */
    public const AVATAR_REMOVAL_PENDING_SESSION_KEY = 'filament_profile_avatar_pending_removal';

    /** Pending file on local disk until profile form save (path: relative to Storage::disk('local')). */
    public const AVATAR_UPLOAD_PENDING_SESSION_KEY = 'filament_profile_avatar_pending_upload';

    public int $userId;

    /** @var TemporaryUploadedFile|array|null */
    public mixed $avatar = null;

    public function mount(?Model $record = null): void
    {
        $user = $record instanceof User ? $record : Auth::user();
        if (! $user instanceof User) {
            abort(Response::HTTP_FORBIDDEN);
        }

        abort_unless(Auth::id() === $user->getKey(), Response::HTTP_FORBIDDEN);

        $this->userId = $user->getKey();
    }

    /**
     * Drop any in-session pending upload and optionally delete its temp file.
     */
    public static function clearPendingUploadFromSession(bool $deleteFile = true): void
    {
        $pending = session()->pull(self::AVATAR_UPLOAD_PENDING_SESSION_KEY);
        if ($deleteFile && is_array($pending) && isset($pending['path'])) {
            Storage::disk('local')->delete((string) $pending['path']);
        }
    }

    /**
     * Persist pending upload to media library; deletes temp file and clears session.
     */
    public static function commitPendingUploadToUser(User $user): void
    {
        $pending = session()->pull(self::AVATAR_UPLOAD_PENDING_SESSION_KEY);
        if (! is_array($pending) || ! isset($pending['user_id'], $pending['path'])) {
            return;
        }

        if ((int) $pending['user_id'] !== (int) $user->getKey()) {
            Storage::disk('local')->delete((string) $pending['path']);

            return;
        }

        $relative = (string) $pending['path'];
        if (! Storage::disk('local')->exists($relative)) {
            return;
        }

        $fullPath = Storage::disk('local')->path($relative);
        if (! is_readable($fullPath)) {
            Storage::disk('local')->delete($relative);

            return;
        }

        try {
            $user->clearMediaCollection('avatar');
            $user
                ->addMedia($fullPath)
                ->usingFileName(basename($fullPath))
                ->usingName('avatar')
                ->toMediaCollection('avatar', config('media-library.disk_name', 'public'));
        } catch (Throwable $e) {
            report($e);
            Notification::make()
                ->title('Profielfoto kon niet worden opgeslagen')
                ->danger()
                ->send();
        } finally {
            Storage::disk('local')->delete($relative);
        }
    }

    public function updatedAvatar(): void
    {
        if (! $this->avatar instanceof TemporaryUploadedFile) {
            return;
        }

        $this->validate([
            'avatar' => ['required', 'image', 'max:5120'],
        ], [
            'avatar.required' => 'Kies een afbeelding.',
            'avatar.image' => 'Het bestand moet een afbeelding zijn.',
            'avatar.max' => 'De afbeelding mag maximaal 5 MB zijn.',
        ]);

        $upload = $this->avatar;
        if (! $upload instanceof TemporaryUploadedFile) {
            return;
        }

        if (User::query()->find($this->userId) === null) {
            return;
        }

        session()->forget(self::AVATAR_REMOVAL_PENDING_SESSION_KEY);
        self::clearPendingUploadFromSession(deleteFile: true);

        try {
            $relativePath = $upload->store('profile-avatar-pending', 'local');
            if ($relativePath === false || $relativePath === '') {
                throw new RuntimeException('Upload tijdelijk niet opgeslagen. Probeer opnieuw.');
            }

            session([
                self::AVATAR_UPLOAD_PENDING_SESSION_KEY => [
                    'user_id' => $this->userId,
                    'path' => $relativePath,
                ],
            ]);
        } catch (Throwable $e) {
            report($e);
            Notification::make()
                ->title('Profielfoto kon niet worden voorbereid')
                ->danger()
                ->send();
            $this->reset('avatar');

            return;
        }

        $this->reset('avatar');
    }

    public function removeAvatar(): void
    {
        self::clearPendingUploadFromSession(deleteFile: true);

        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        if ($user->getFirstMedia('avatar') !== null) {
            session([self::AVATAR_REMOVAL_PENDING_SESSION_KEY => $this->userId]);
        }
    }

    public function render(): View
    {
        $user = User::query()
            ->with('media')
            ->find($this->userId);

        $avatarUrl = null;
        $hadAvatarInDb = $user instanceof User && $user->getFirstMedia('avatar') !== null;
        $pendingRemoval = (int) session(self::AVATAR_REMOVAL_PENDING_SESSION_KEY, 0) === $this->userId;

        $pendingUpload = session(self::AVATAR_UPLOAD_PENDING_SESSION_KEY);
        $hasPendingUpload = is_array($pendingUpload)
            && (int) ($pendingUpload['user_id'] ?? 0) === $this->userId
            && isset($pendingUpload['path'])
            && Storage::disk('local')->exists((string) $pendingUpload['path']);

        if ($hasPendingUpload) {
            $avatarUrl = Filament::getCurrentPanel()->route('pending-avatar-preview');
        } elseif ($hadAvatarInDb && ! $pendingRemoval) {
            $medium = $user->getFirstMediaUrl('avatar', 'medium');
            $avatarUrl = $medium !== '' ? $medium : $user->getFirstMediaUrl('avatar');
            if ($avatarUrl === '') {
                $avatarUrl = null;
            }
        }

        $showRemoveOrCancel = ($hadAvatarInDb && ! $pendingRemoval) || $hasPendingUpload;

        return view('livewire.profile-avatar-upload', [
            'avatarUrl' => $avatarUrl,
            'hasCustomAvatar' => $showRemoveOrCancel,
        ]);
    }
}
