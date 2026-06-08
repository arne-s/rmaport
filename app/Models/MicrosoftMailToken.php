<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $access_token
 * @property string|null $refresh_token
 * @property string|null $microsoft_email
 * @property bool $is_default
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MicrosoftMailToken extends Model
{
    protected $fillable = [
        'access_token',
        'refresh_token',
        'microsoft_email',
        'is_default',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'is_default' => 'boolean',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Return the email address of the default token, or the configured
     * fallback address, or null if neither is set.
     */
    public static function defaultEmail(): ?string
    {
        return static::where('is_default', true)->value('microsoft_email')
            ?? config('mail.fallback_from_address');
    }

    /**
     * Mark this token as the default, clearing the flag on all others.
     */
    public function setAsDefault(): void
    {
        DB::transaction(function () {
            static::query()->where('id', '!=', $this->id)->update(['is_default' => false]);
            $this->update(['is_default' => true]);
        });
    }
}
