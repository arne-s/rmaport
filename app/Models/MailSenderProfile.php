<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int|null $microsoft_mail_token_id
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MicrosoftMailToken|null $microsoftMailToken
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EmailTemplate> $emailTemplates
 * @method static Builder|MailSenderProfile newModelQuery()
 * @method static Builder|MailSenderProfile newQuery()
 * @method static Builder|MailSenderProfile query()
 * @method static Builder|MailSenderProfile scopeDefault(Builder $query)
 */
class MailSenderProfile extends Model
{
    protected $fillable = [
        'name',
        'uid',
        'microsoft_mail_token_id',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function microsoftMailToken(): BelongsTo
    {
        return $this->belongsTo(MicrosoftMailToken::class);
    }

    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }

    /** @param Builder<MailSenderProfile> $query */
    public function scopeDefault(Builder $query): void
    {
        $query->where('is_default', true);
    }

    public static function tokenIdByUid(string $uid): ?int
    {
        return static::where('uid', $uid)->value('microsoft_mail_token_id');
    }

    /**
     * Display value for the disabled "From" field in mail modals (profile name, not only the mailbox address).
     * When $uid is set, uses that sender profile; otherwise the default profile, then the default Microsoft mailbox.
     */
    public static function modalFromDisplayLabel(?string $uid = null): string
    {
        if ($uid !== null && $uid !== '') {
            $named = static::query()->where('uid', $uid)->first();
            if ($named !== null) {
                return $named->name;
            }
        }

        $profile = static::query()->where('is_default', true)->first();

        return $profile?->name ?? (MicrosoftMailToken::defaultEmail() ?? '');
    }

    protected static function booted(): void
    {
        static::saving(function (MailSenderProfile $profile): void {
            if ($profile->is_default) {
                static::where('id', '!=', $profile->id ?? 0)->update(['is_default' => false]);
            }
        });
    }
}
