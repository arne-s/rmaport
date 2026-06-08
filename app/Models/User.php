<?php

namespace App\Models;

use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Database\Factories\UserFactory;
use Barryvdh\LaravelIdeHelper\Eloquent;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Althinect\FilamentSpatieRolesPermissions\Concerns\HasSuperAdmin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;


/**
 * App\Models\User
 *
 * @property int $id
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property int|null $country_id
 * @property string|null $city
 * @property string|null $postcode
 * @property string|null $house_number_addition
 * @property string|null $house_number
 * @property string|null $street
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property string|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $phone_number
 * @property string|null $mobile_number
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $middle_name
 * @property string|null $activated_at
 * @property string|null $activation_token
 * @property string|null $salutation
 * @property int|null $region_id
 * @property bool $requires_app_2fa
 * @property string|null $app_authentication_secret
 * @property array<string>|null $app_authentication_recovery_codes
 * @property-read Company|null $company
 * @property-read Country|null $country
 * @property-read mixed $has_confirmed_two_factor
 * @property-read mixed $has_enabled_two_factor
 * @property-read string $name
 * @property-read MediaCollection<int, Media> $media
 * @property-read int|null $media_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read Collection<int, Role> $roles
 * @property-read int|null $roles_count
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static UserFactory factory($count = null, $state = [])
 * @method static Builder|User newModelQuery()
 * @method static Builder|User newQuery()
 * @method static Builder|User permission($permissions)
 * @method static Builder|User query()
 * @method static Builder|User role($roles, $guard = null)
 * @method static Builder|User whereActivatedAt($value)
 * @method static Builder|User whereActivationToken($value)
 * @method static Builder|User whereCity($value)
 * @method static Builder|User whereCountryId($value)
 * @method static Builder|User whereCreatedAt($value)
 * @method static Builder|User whereEmail($value)
 * @method static Builder|User whereEmailVerifiedAt($value)
 * @method static Builder|User whereFirstName($value)
 * @method static Builder|User whereHouseNumber($value)
 * @method static Builder|User whereHouseNumberAddition($value)
 * @method static Builder|User whereId($value)
 * @method static Builder|User whereLastName($value)
 * @method static Builder|User whereMiddleName($value)
 * @method static Builder|User whereMobileNumber($value)
 * @method static Builder|User wherePassword($value)
 * @method static Builder|User wherePhoneNumber($value)
 * @method static Builder|User wherePostcode($value)
 * @method static Builder|User whereRegionId($value)
 * @method static Builder|User whereRememberToken($value)
 * @method static Builder|User whereSalutation($value)
 * @method static Builder|User whereStreet($value)
 * @method static Builder|User whereTwoFactorConfirmedAt($value)
 * @method static Builder|User whereTwoFactorRecoveryCodes($value)
 * @method static Builder|User whereTwoFactorSecret($value)
 * @method static Builder|User whereUpdatedAt($value)
 * @mixin Eloquent
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasAvatar, HasMedia
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes,
        HasSuperAdmin,
        InteractsWithAppAuthentication,
        InteractsWithAppAuthenticationRecovery,
        interactsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'password',
        'city',
        'first_name',
        'middle_name',
        'last_name',
        'house_number_addition',
        'house_number',
        'street',
        'postcode',
        'phone_number',
        'mobile_number',
        'salutation',
        'country_id',
        'region_id',
        'last_online_at',
        'requires_app_2fa',
        'activation_token',
        'activated_at',
    ];

    protected $with = ['roles', 'country', 'region'];

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->isDirty('requires_app_2fa') && ! $user->getRequiresApp2fa()) {
                $user->setApp2faSecret(null);
                $user->setApp2faRecoveryCodes(null);
            }
        });
    }

    public function getRequiresApp2fa(): bool
    {
        return (bool) $this->requires_app_2fa;
    }

    public function setRequiresApp2fa(bool $value): self
    {
        $this->requires_app_2fa = $value;

        return $this;
    }

    public function getApp2faSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function setApp2faSecret(?string $secret): self
    {
        $this->app_authentication_secret = $secret;

        return $this;
    }

    /**
     * @return ?array<string>
     */
    public function getApp2faRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function setApp2faRecoveryCodes(?array $codes): self
    {
        $this->app_authentication_recovery_codes = $codes;

        return $this;
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->getApp2faSecret();
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->setApp2faSecret($secret);
        $this->save();
    }

    /**
     * @return ?array<string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->getApp2faRecoveryCodes();
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->setApp2faRecoveryCodes($codes);
        $this->save();
    }


    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_online_at' => 'datetime',
            'requires_app_2fa' => 'boolean',
        ];
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public static function rules(): array
    {
        return [
            'first_name' => ['required'],
            'last_name' => ['required'],
            'middle_name' => ['nullable'],
            'salutation' => ['nullable'],
            'email' => ['email'],
            'phone_number' => ['nullable', 'numeric', 'digits_between:1,12'],
            'street' => ['required'],
            'city' => ['required'],
            'house_number' => ['required'],
            'house_number_addition' => ['nullable'],
        ];
    }


    public function canAccessPanel(Panel|string $panel): bool
    {
        if ($this->trashed()) {
            return false;
        }

        return $this->can('access filament panel');
    }

    /**
     * @return string
     *
     * Returns route name to redirect to after login
     */
    public function getRedirectRoute(): string
    {
        return $this->can('access filament panel')
            ? 'filament.app.pages.dashboard'
            : 'home';
    }

    /**
     * Get the full name of the invoice name.
     *
     * @return string
     */
    public function getInvoiceNameAttribute(): string
    {
        return implode(' ',
            array_filter([
                $this->getFirstName(),
                $this->getMiddleName(),
                $this->getLastName()
            ])
        );
    }

    public function getAddress(): string
    {
        return trim(implode(' ', [
            $this->getStreet() ?? '',
            $this->getHouseNumber() ?? '',
            $this->getHouseNumberAddition() ?? '']));
    }

    /**
     * Get the full name of the user.
     *
     * @return string
     */
    public function getNameAttribute(): string
    {
        return $this->getName();
    }

    /**
     * Get the full name of the user.
     *
     * @return string
     */
    public function getName(): string
    {
        return implode(' ',
            array_filter([
                $this->first_name,
                $this->middle_name,
                $this->last_name
            ])
        );
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeAdvisors(Builder $query): Builder
    {
        return $query->role('advisor');
    }

    /**
     * @return array<int, string>
     */
    public static function advisorOptionsForSelect(): array
    {
        return static::query()
            ->advisors()
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => $user->first_name . ' ' . $user->last_name . ' (' . $user->email . ')',
            ])
            ->all();
    }

    public function getFullLastName(): string
    {
        return implode(' ', array_filter([$this->middle_name, $this->last_name]));
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function sentChatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'from_user_id');
    }

    public function receivedChatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'to_user_id');
    }

    public function touchLastOnline(): void
    {
        $this->forceFill(['last_online_at' => now()])->saveQuietly();
    }

    /**
     * Panel presence heartbeat runs every 60s; allow slack so users stay "online" between ticks.
     */
    public function isConsideredOnline(int $maxAgeSeconds = 120): bool
    {
        return $this->last_online_at !== null
            && $this->last_online_at->greaterThanOrEqualTo(now()->subSeconds($maxAgeSeconds));
    }

    public function getFilamentAvatarUrl(): ?string
    {
        $url = $this->getFirstMediaUrl('avatar', 'medium');

        if ($url !== '') {
            return $url;
        }

        $original = $this->getFirstMediaUrl('avatar');

        return $original !== '' ? $original : null;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->singleFile()
            ->useDisk(config('media-library.disk_name', 'public'));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this
            ->addMediaConversion('medium')
            ->fit(Fit::Crop, 152, 152)
            ->nonQueued();
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @param string $email
     * @return User
     */
    public function setEmail(string $email): User
    {
        $this->email = $email;
        return $this;
    }

    /**
     * @return Carbon|null
     */
    public function getEmailVerifiedAt(): ?Carbon
    {
        return $this->email_verified_at;
    }

    /**
     * @param Carbon|null $email_verified_at
     * @return User
     */
    public function setEmailVerifiedAt(?Carbon $email_verified_at): User
    {
        $this->email_verified_at = $email_verified_at;
        return $this;
    }

    /**
     * @param string|null $password
     * @return User
     */
    public function setPassword(?string $password): User
    {
        $this->password = $password;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getCountryId(): ?int
    {
        return $this->country_id;
    }

    /**
     * @param int|null $country_id
     * @return User
     */
    public function setCountryId(?int $country_id): User
    {
        $this->country_id = $country_id;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCity(): ?string
    {
        return $this->city;
    }

    /**
     * @param string|null $city
     * @return User
     */
    public function setCity(?string $city): User
    {
        $this->city = $city;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getPostcode(): ?string
    {
        return $this->postcode;
    }

    /**
     * @param string|null $postcode
     * @return User
     */
    public function setPostcode(?string $postcode): User
    {
        $this->postcode = $postcode;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getHouseNumberAddition(): ?string
    {
        return $this->house_number_addition;
    }

    /**
     * @param string|null $house_number_addition
     * @return User
     */
    public function setHouseNumberAddition(?string $house_number_addition): User
    {
        $this->house_number_addition = $house_number_addition;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getHouseNumber(): ?string
    {
        return $this->house_number;
    }

    /**
     * @param string|null $house_number
     * @return User
     */
    public function setHouseNumber(?string $house_number): User
    {
        $this->house_number = $house_number;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getStreet(): ?string
    {
        return $this->street;
    }

    /**
     * @param string|null $street
     * @return User
     */
    public function setStreet(?string $street): User
    {
        $this->street = $street;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getRememberToken(): ?string
    {
        return $this->remember_token;
    }

    /**
     * Get the expiration time for the remember token.
     *
     * @return Carbon
     */
    public function getRememberTokenExpiration(): Carbon
    {
        $seconds = (int) env('REMEMBER_TOKEN_EXPIRATION_SECONDS', 30 * 24 * 60 * 60);

        return now()->addSeconds($seconds);
    }

    /**
     * @return Carbon|null
     */
    public function getCreatedAt(): ?Carbon
    {
        return $this->created_at;
    }

    /**
     * @return Carbon|null
     */
    public function getUpdatedAt(): ?Carbon
    {
        return $this->updated_at;
    }


    /**
     * @return string|null
     */
    public function getPhoneNumber(): ?string
    {
        return $this->phone_number;
    }

    /**
     * @param string|null $phone_number
     * @return User
     */
    public function setPhoneNumber(?string $phone_number): User
    {
        $this->phone_number = $phone_number;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getMobileNumber(): ?string
    {
        return $this->mobile_number;
    }

    /**
     * @param string|null $mobile_number
     * @return User
     */
    public function setMobileNumber(?string $mobile_number): User
    {
        $this->mobile_number = $mobile_number;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getFirstName(): ?string
    {
        return $this->first_name;
    }

    /**
     * @param string|null $first_name
     * @return User
     */
    public function setFirstName(?string $first_name): User
    {
        $this->first_name = $first_name;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getLastName(): ?string
    {
        return $this->last_name;
    }

    /**
     * @param string|null $last_name
     * @return User
     */
    public function setLastName(?string $last_name): User
    {
        $this->last_name = $last_name;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getMiddleName(): ?string
    {
        return $this->middle_name;
    }

    /**
     * @param string|null $middle_name
     * @return User
     */
    public function setMiddleName(?string $middle_name): User
    {
        $this->middle_name = $middle_name;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getActivatedAt(): ?string
    {
        return $this->activated_at;
    }

    /**
     * @param string|null $activated_at
     * @return User
     */
    public function setActivatedAt(?string $activated_at): User
    {
        $this->activated_at = $activated_at;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getActivationToken(): ?string
    {
        return $this->activation_token;
    }

    /**
     * @param string|null $activation_token
     * @return User
     */
    public function setActivationToken(?string $activation_token): User
    {
        $this->activation_token = $activation_token;
        return $this;
    }

    public function hasPendingActivation(): bool
    {
        return filled($this->activation_token);
    }

    /**
     * @return mixed
     */
    public function getHasConfirmedTwoFactor(): mixed
    {
        return $this->has_confirmed_two_factor;
    }

    /**
     * @param mixed $has_confirmed_two_factor
     * @return User
     */
    public function setHasConfirmedTwoFactor(mixed $has_confirmed_two_factor): User
    {
        $this->has_confirmed_two_factor = $has_confirmed_two_factor;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getHasEnabledTwoFactor(): mixed
    {
        return $this->has_enabled_two_factor;
    }

    /**
     * @param mixed $has_enabled_two_factor
     * @return User
     */
    public function setHasEnabledTwoFactor(mixed $has_enabled_two_factor): User
    {
        $this->has_enabled_two_factor = $has_enabled_two_factor;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getMediaCount(): ?int
    {
        return $this->media_count;
    }

    /**
     * @return int|null
     */
    public function getNotificationsCount(): ?int
    {
        return $this->notifications_count;
    }

    /**
     * @param int|null $notifications_count
     * @return User
     */
    public function setNotificationsCount(?int $notifications_count): User
    {
        $this->notifications_count = $notifications_count;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getPermissionsCount(): ?int
    {
        return $this->permissions_count;
    }

    /**
     * @param int|null $permissions_count
     * @return User
     */
    public function setPermissionsCount(?int $permissions_count): User
    {
        $this->permissions_count = $permissions_count;
        return $this;
    }

    /**
     * @return Company|null
     */
    public function getCompany(): ?Company
    {
        return $this->company;
    }

    /**
     * @return string|null
     */
    public function getSalutation(): ?string
    {
        return $this->salutation;
    }

    /**
     * @param string|null $salutation
     * @return User
     */
    public function setSalutation(?string $salutation): User
    {
        $this->salutation = $salutation;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getRegionId(): ?int
    {
        return $this->region_id;
    }

    /**
     * @param int|null $region_id
     * @return User
     */
    public function setRegionId(?int $region_id): User
    {
        $this->region_id = $region_id;
        return $this;
    }

    /**
     * @return Country|null
     */
    public function getCountry(): ?Country
    {
        return $this->country;
    }

    /**
     * @param Country|null $country
     * @return User
     */
    public function setCountry(?Country $country): User
    {
        $this->country = $country;
        return $this;
    }


}
