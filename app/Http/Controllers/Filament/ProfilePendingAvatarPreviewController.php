<?php

namespace App\Http\Controllers\Filament;

use App\Http\Livewire\ProfileAvatarUpload;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ProfilePendingAvatarPreviewController
{
    public function __invoke(): BinaryFileResponse
    {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }

        $pending = session(ProfileAvatarUpload::AVATAR_UPLOAD_PENDING_SESSION_KEY);
        if (! is_array($pending) || ! isset($pending['user_id'], $pending['path'])) {
            abort(404);
        }

        if ((int) $pending['user_id'] !== (int) $user->getKey()) {
            abort(403);
        }

        $relative = $pending['path'];
        if (! Storage::disk('local')->exists($relative)) {
            abort(404);
        }

        $absolute = Storage::disk('local')->path($relative);

        return response()->file($absolute);
    }
}
