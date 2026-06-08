<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Services\RecordLockService;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class RecordLockNavigation
{
    /**
     * Redirect to an edit page when no other user holds an active lock; otherwise show a toast.
     */
    public static function attemptRedirectToEdit(Component $livewire, Model $lockable, string $editUrl, bool $navigate = true): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            $livewire->redirect($editUrl, navigate: $navigate);

            return true;
        }

        $details = app(RecordLockService::class)->getBlockedDetailsFor($lockable, $user, $editUrl);

        if ($details !== null) {
            static::notifyDocumentInUse($details);

            return false;
        }

        $livewire->redirect($editUrl, navigate: $navigate);

        return true;
    }

    /**
     * @param  array{holderName: string, lockedAt: string, expiresAt: string, backUrl: string}  $details
     */
    public static function notifyDocumentInUse(array $details): void
    {
        Notification::make()
            ->title('Document in gebruik')
            ->body(sprintf(
                '%s bekijkt dit document momenteel (sinds %s, geldig tot %s).',
                $details['holderName'],
                $details['lockedAt'],
                $details['expiresAt'],
            ))
            ->danger()
            ->persistent()
            ->send();
    }

    public static function notifyRevisionAlreadyStarted(string $documentLabel, ?string $startedByUserName = null): void
    {
        $body = $startedByUserName !== null && $startedByUserName !== ''
            ? sprintf('Deze %s is al herzien door %s.', $documentLabel, $startedByUserName)
            : sprintf('Deze %s is al herzien door een andere gebruiker.', $documentLabel);

        Notification::make()
            ->title('Revisie niet mogelijk')
            ->body($body)
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * @param  array{holderName: string, lockedAt: string, expiresAt: string, backUrl: string, productLabel: string}  $details
     */
    public static function notifyOrderProductSelectionBlocked(array $details): void
    {
        $productLabel = $details['productLabel'];

        if ($details['holderName'] === '') {
            Notification::make()
                ->title('Artikel niet beschikbaar')
                ->body(sprintf('"%s" is niet meer beschikbaar voor inkoop of afroep. Vernieuw de pagina.', $productLabel))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title('Artikel in gebruik')
            ->body(sprintf(
                '%s werkt momenteel aan "%s" (sinds %s, geldig tot %s).',
                $details['holderName'],
                $productLabel,
                $details['lockedAt'],
                $details['expiresAt'],
            ))
            ->danger()
            ->persistent()
            ->send();
    }
}
