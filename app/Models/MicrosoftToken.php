<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $access_token
 * @property string|null $refresh_token
 * @property string|null $microsoft_email
 * @property string|null $calendar_id
 * @property string|null $calendar_name
 * @property int|null $role_id
 * @property string|null $calendar_display_name
 * @property string|null $general_category_name
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Role|null $role
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MicrosoftCategoryMapping> $categoryMappings
 */
class MicrosoftToken extends Model
{
    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'microsoft_email',
        'calendar_id',
        'calendar_name',
        'role_id',
        'calendar_display_name',
        'general_category_name',
        'expires_at',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function categoryMappings(): HasMany
    {
        return $this->hasMany(MicrosoftCategoryMapping::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public static function resolveForRole(Role|SpatieRole|int|string $role): ?self
    {
        $roleId = match (true) {
            $role instanceof Role, $role instanceof SpatieRole => $role->id,
            is_int($role) => $role,
            default => Role::query()
                ->where('name', (string) $role)
                ->where('guard_name', 'web')
                ->value('id'),
        };

        if ($roleId === null) {
            return null;
        }

        return static::query()
            ->where('role_id', $roleId)
            ->orderBy('id')
            ->first();
    }

    public static function resolveForRoleName(string $roleName): ?self
    {
        return static::resolveForRole($roleName);
    }

    public function getCalendarDisplayLabel(): string
    {
        if (filled($this->calendar_display_name)) {
            return (string) $this->calendar_display_name;
        }

        return (string) ($this->microsoft_email ?? '');
    }
}
