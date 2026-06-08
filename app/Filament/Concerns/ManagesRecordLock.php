<?php

namespace App\Filament\Concerns;

use App\Models\RecordLock;
use App\Models\User;
use App\Services\RecordLockService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Reusable exclusive edit lock for Filament {@see \Filament\Resources\Pages\EditRecord} pages.
 *
 * Setup on a new edit page:
 * 1. `use ManagesRecordLock;`
 * 2. `protected string $view = RecordLockEditPage::VIEW;`
 * 3. At the start of `mount()`: `if (! $this->mountRecordLockGate($record)) { return; }`
 * 4. After `parent::mount($record)`: `$this->completeRecordLockMount();`
 * 5. Wrap `getFormActions()` with `formActionsUnlessRecordLockBlocked([...])`
 * 6. Optionally override {@see getRecordLockBackUrl()}, {@see getRecordLockAdditionalBlade()}, or {@see shouldUseRecordLock()}
 *
 * Expects {@see $record} on the Livewire component (standard EditRecord).
 *
 * @property Model $record
 */
trait ManagesRecordLock
{
    /**
     * @var array{holderName: string, lockedAt: string, expiresAt: string, backUrl: string}|null
     */
    public ?array $recordLockBlockedDetails = null;

    public function isRecordLockBlocked(): bool
    {
        return $this->recordLockBlockedDetails !== null;
    }

    public function usesRecordLock(): bool
    {
        return $this->shouldUseRecordLock();
    }

    /**
     * Opt out per page (e.g. while rolling out locks gradually).
     */
    protected function shouldUseRecordLock(): bool
    {
        return true;
    }

    /**
     * Call at the beginning of `mount()`. Returns false when another user holds the lock
     * (stop mounting; the layout shows {@see recordLockBlockedDetails} instead of the form).
     */
    protected function mountRecordLockGate(int|string $record): bool
    {
        if (! $this->shouldUseRecordLock()) {
            return true;
        }

        $resolvedRecord = $this->resolveRecord($record);

        if (! $this->guardRecordLockBeforeMount($resolvedRecord)) {
            return true;
        }

        $this->record = $resolvedRecord;
        $this->authorizeAccess();
        $this->previousUrl = url()->previous();

        return false;
    }

    /**
     * Call immediately after `parent::mount()` when {@see mountRecordLockGate()} returned true.
     */
    protected function completeRecordLockMount(): void
    {
        if (! $this->shouldUseRecordLock() || $this->isRecordLockBlocked()) {
            return;
        }

        $this->acquireRecordLock();
    }

    /**
     * @param  array<int, mixed>  $actions
     * @return array<int, mixed>
     */
    protected function formActionsUnlessRecordLockBlocked(array $actions): array
    {
        if ($this->shouldUseRecordLock() && $this->isRecordLockBlocked()) {
            return [];
        }

        return $actions;
    }

    /**
     * Extra markup below {@see \Filament\Resources\Pages\EditRecord} content (modals, etc.).
     */
    public function getRecordLockAdditionalBlade(): ?string
    {
        return null;
    }

    protected function guardRecordLockBeforeMount(Model $record): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        $blockingLock = app(RecordLockService::class)->getBlockingLock($record, $user);

        if ($blockingLock === null) {
            return false;
        }

        $this->blockRecordLock($blockingLock);

        return true;
    }

    protected function acquireRecordLock(): void
    {
        if (! $this->shouldUseRecordLock()) {
            return;
        }

        $user = Auth::user();

        if (! $user instanceof User || ! isset($this->record) || ! $this->record instanceof Model) {
            return;
        }

        if ($this->isRecordLockBlocked()) {
            return;
        }

        $lockService = app(RecordLockService::class);
        $lock = $lockService->acquire($this->record, $user);

        if ($lock->isActive() && ! $lock->isHeldBy($user)) {
            $this->blockRecordLock($lock);
        }
    }

    protected function releaseRecordLock(): void
    {
        $user = Auth::user();

        if (! $user instanceof User || ! isset($this->record) || ! $this->record instanceof Model) {
            return;
        }

        app(RecordLockService::class)->release($this->record, $user);
    }

    public function refreshRecordLock(): void
    {
        $this->acquireRecordLock();
    }

    public function hydrate(): void
    {
        if ($this->isRecordLockBlocked() || ! $this->shouldUseRecordLock()) {
            return;
        }

        $this->acquireRecordLock();
    }

    protected function blockRecordLock(RecordLock $lock): void
    {
        $this->recordLockBlockedDetails = app(RecordLockService::class)->toBlockedDetails(
            $lock,
            $this->getRecordLockBackUrl(),
        );
    }

    /**
     * Where "Terug naar overzicht" points when the record is locked.
     */
    protected function getRecordLockBackUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    public function getRecordLockPollInterval(): string
    {
        $seconds = max(15, (int) config('record_locks.refresh_poll_seconds', 60));

        return "{$seconds}s";
    }
}
