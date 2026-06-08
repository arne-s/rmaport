<?php

namespace App\Services;

use App\Models\RecordLock;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecordLockService
{
    public function ttlSeconds(): int
    {
        return max(60, (int) config('record_locks.ttl_seconds', 900));
    }

    /**
     * Returns an active lock held by another user, if any.
     */
    public function getBlockingLock(Model $lockable, User $user): ?RecordLock
    {
        $this->purgeExpiredFor($lockable);

        $lock = $this->findLockFor($lockable);

        if ($lock === null || ! $lock->isActive()) {
            return null;
        }

        if ($lock->isHeldBy($user)) {
            return null;
        }

        return $lock->loadMissing('user');
    }

    /**
     * @return array{holderName: string, lockedAt: string, expiresAt: string, backUrl: string}|null
     */
    public function getBlockedDetailsFor(Model $lockable, User $user, string $backUrl): ?array
    {
        $lock = $this->getBlockingLock($lockable, $user);

        if ($lock === null) {
            return null;
        }

        return $this->toBlockedDetails($lock, $backUrl);
    }

    public function release(Model $lockable, User $user): void
    {
        RecordLock::query()
            ->where('lockable_type', $lockable->getMorphClass())
            ->where('lockable_id', $lockable->getKey())
            ->where('user_id', $user->id)
            ->delete();
    }

    /**
     * @param  iterable<int, Model>  $lockables
     */
    public function releaseAll(iterable $lockables, User $user): void
    {
        foreach ($lockables as $lockable) {
            $this->release($lockable, $user);
        }
    }

    public function isHeldByCurrentUser(Model $lockable, User $user): bool
    {
        $this->purgeExpiredFor($lockable);

        $lock = $this->findLockFor($lockable);

        return $lock !== null
            && $lock->isActive()
            && $lock->isHeldBy($user);
    }

    /**
     * Acquire locks on all records in one transaction, or return the first blocking lock held by another user.
     *
     * @param  Collection<int, Model>|iterable<int, Model>  $lockables
     */
    public function acquireAllOrGetBlocking(iterable $lockables, User $user): ?RecordLock
    {
        $lockables = collect($lockables)->values();

        if ($lockables->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($lockables, $user): ?RecordLock {
            $acquired = [];

            foreach ($lockables as $lockable) {
                $lock = $this->acquireWithoutTransaction($lockable, $user);

                if (! $lock->isHeldBy($user)) {
                    foreach ($acquired as $acquiredLockable) {
                        RecordLock::query()
                            ->where('lockable_type', $acquiredLockable->getMorphClass())
                            ->where('lockable_id', $acquiredLockable->getKey())
                            ->where('user_id', $user->id)
                            ->delete();
                    }

                    return $lock->loadMissing('user');
                }

                $acquired[] = $lockable;
            }

            return null;
        });
    }

    /**
     * Create or extend the current user's lock on the given record.
     */
    public function acquire(Model $lockable, User $user): RecordLock
    {
        return DB::transaction(fn (): RecordLock => $this->acquireWithoutTransaction($lockable, $user));
    }

    protected function acquireWithoutTransaction(Model $lockable, User $user): RecordLock
    {
        $this->purgeExpiredFor($lockable);

        $now = now();
        $expiresAt = $now->copy()->addSeconds($this->ttlSeconds());

        $lock = RecordLock::query()
            ->where('lockable_type', $lockable->getMorphClass())
            ->where('lockable_id', $lockable->getKey())
            ->lockForUpdate()
            ->first();

        if ($lock !== null && $lock->isActive()) {
            if ($lock->isHeldBy($user)) {
                $lock->update(['expires_at' => $expiresAt]);

                return $lock->refresh();
            }

            return $lock->loadMissing('user');
        }

        if ($lock !== null) {
            $lock->delete();
        }

        return RecordLock::query()->create([
            'lockable_type' => $lockable->getMorphClass(),
            'lockable_id' => $lockable->getKey(),
            'user_id' => $user->id,
            'locked_at' => $now,
            'expires_at' => $expiresAt,
        ]);
    }

    public function lockedAtFormatted(RecordLock $lock): string
    {
        return $this->formatDateTime($lock->locked_at);
    }

    public function expiresAtFormatted(RecordLock $lock): string
    {
        return $this->formatDateTime($lock->expires_at);
    }

    /**
     * @return array{holderName: string, lockedAt: string, expiresAt: string, backUrl: string}
     */
    public function toBlockedDetails(RecordLock $lock, string $backUrl): array
    {
        $lock->loadMissing('user');

        return [
            'holderName' => $lock->user->getName(),
            'lockedAt' => $this->lockedAtFormatted($lock),
            'expiresAt' => $this->expiresAtFormatted($lock),
            'backUrl' => $backUrl,
        ];
    }

    protected function findLockFor(Model $lockable): ?RecordLock
    {
        return RecordLock::query()
            ->where('lockable_type', $lockable->getMorphClass())
            ->where('lockable_id', $lockable->getKey())
            ->first();
    }

    protected function purgeExpiredFor(Model $lockable): void
    {
        RecordLock::query()
            ->where('lockable_type', $lockable->getMorphClass())
            ->where('lockable_id', $lockable->getKey())
            ->where('expires_at', '<=', now())
            ->delete();
    }

    protected function formatDateTime(Carbon $dateTime): string
    {
        return $dateTime->translatedFormat('j M Y H:i');
    }
}
