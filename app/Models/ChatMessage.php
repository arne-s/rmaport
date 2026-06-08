<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $from_user_id
 * @property int $to_user_id
 * @property string $content
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $fromUser
 * @property-read User $toUser
 */
class ChatMessage extends Model
{
    protected $fillable = [
        'from_user_id',
        'to_user_id',
        'content',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * Messages between two users (both directions).
     */
    public function scopeBetweenUsers(Builder $query, int $userIdA, int $userIdB): Builder
    {
        return $query->where(function (Builder $q) use ($userIdA, $userIdB): void {
            $q->where(function (Builder $q2) use ($userIdA, $userIdB): void {
                $q2->where('from_user_id', $userIdA)->where('to_user_id', $userIdB);
            })->orWhere(function (Builder $q2) use ($userIdA, $userIdB): void {
                $q2->where('from_user_id', $userIdB)->where('to_user_id', $userIdA);
            });
        });
    }

    public static function unreadCountForUser(int $userId): int
    {
        return static::query()
            ->where('to_user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * For each sender, how many unread messages they sent to $recipientUserId.
     *
     * @return Collection<int, int> from_user_id => count
     */
    public static function unreadCountsBySenderForRecipient(int $recipientUserId): Collection
    {
        return static::query()
            ->where('to_user_id', $recipientUserId)
            ->whereNull('read_at')
            ->groupBy('from_user_id')
            ->selectRaw('from_user_id, COUNT(*) as unread_count')
            ->pluck('unread_count', 'from_user_id')
            ->map(static fn (mixed $count): int => (int) $count);
    }

    /**
     * User id of the sender of the most recent unread message addressed to $recipientUserId.
     */
    public static function latestUnreadSenderIdForRecipient(int $recipientUserId): ?int
    {
        $message = static::query()
            ->where('to_user_id', $recipientUserId)
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($message === null) {
            return null;
        }

        return (int) $message->getAttribute('from_user_id');
    }

    /**
     * De andere gebruiker in het meest recente bericht waarbij $userId betrokken is (verzonden of ontvangen).
     */
    public static function latestConversationPartnerUserId(int $userId): ?int
    {
        $message = static::query()
            ->where(function (Builder $q) use ($userId): void {
                $q->where('from_user_id', $userId)->orWhere('to_user_id', $userId);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($message === null) {
            return null;
        }

        $from = (int) $message->getAttribute('from_user_id');
        $to = (int) $message->getAttribute('to_user_id');

        return $from === $userId ? $to : $from;
    }

    public static function markConversationRead(int $currentUserId, int $otherUserId): int
    {
        return static::query()
            ->where('from_user_id', $otherUserId)
            ->where('to_user_id', $currentUserId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
