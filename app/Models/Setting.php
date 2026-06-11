<?php

namespace App\Models;

use App\Settings\Contracts\SettingDefinition;
use App\Settings\SettingsDefaults;
use App\Support\SettingFormState;
use Filament\Forms\Components\Field;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Setting extends Model
{
    /** @use HasFactory<\Database\Factories\SettingFactory> */
    use HasFactory;

    protected $fillable = [
        'uid',
        'class',
        'name',
        'description',
        'value',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    public static function get(string $uid, mixed $default = null): mixed
    {
        return Cache::rememberForever(static::cacheKey($uid), function () use ($uid, $default): mixed {
            $record = static::query()->where('uid', $uid)->first();

            if ($record === null) {
                Log::warning("Setting [{$uid}] not found in database; using default.");

                $record = static::makeFromDefaults($uid);
            }

            if ($record === null) {
                return $default;
            }

            return $record->runtimeValue();
        });
    }

    public static function set(string $uid, mixed $value): void
    {
        $record = static::query()->firstOrNew(['uid' => $uid]);

        if (! $record->exists) {
            $defaults = SettingsDefaults::rowForUid($uid);

            if ($defaults === null) {
                return;
            }

            $record->fill([
                'class' => $defaults['class'],
                'name' => $defaults['name'],
                'description' => $defaults['description'],
                'sort' => $defaults['sort'],
            ]);
        }

        $record->value = $record->definition()->serialize($value);
        $record->save();
    }

    public static function clearCache(?string $uid = null): void
    {
        if ($uid !== null) {
            Cache::forget(static::cacheKey($uid));

            return;
        }

        foreach (static::query()->pluck('uid') as $cachedUid) {
            Cache::forget(static::cacheKey($cachedUid));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function allAsFlatFormState(): array
    {
        $state = [];

        foreach (static::query()->orderBy('sort')->get() as $record) {
            $definition = $record->definition();
            $stored = $record->value;

            $state[$record->uid] = $definition->deserialize(
                $stored !== null && $stored !== ''
                    ? $stored
                    : $definition->serialize($definition->default()),
            );
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    public static function allAsFormState(): array
    {
        return SettingFormState::toNested(static::allAsFlatFormState());
    }

    public static function makeFromDefaults(string $uid): ?self
    {
        $defaults = SettingsDefaults::rowForUid($uid);

        if ($defaults === null) {
            return null;
        }

        $setting = new self([
            'uid' => $defaults['uid'],
            'class' => $defaults['class'],
            'name' => $defaults['name'],
            'description' => $defaults['description'],
            'sort' => $defaults['sort'],
        ]);

        if ($defaults['value'] !== null && $defaults['value'] !== '') {
            $setting->value = $setting->definition()->serialize($defaults['value']);
        }

        return $setting;
    }

    public function definition(): SettingDefinition
    {
        $class = $this->class ?: SettingsDefaults::definitionClassForUid($this->uid);

        if ($class === null || ! class_exists($class)) {
            throw new \InvalidArgumentException("No definition class for setting [{$this->uid}].");
        }

        return app($class, ['setting' => $this]);
    }

    public function toFormComponent(string $statePath): Field
    {
        return $this->definition()->component($statePath);
    }

    public function runtimeValue(): mixed
    {
        $definition = $this->definition();
        $stored = $this->value;

        if ($stored === null || $stored === '') {
            return $definition->getRuntime(
                $definition->serialize($definition->default()),
            );
        }

        return $definition->getRuntime($stored);
    }

    protected static function cacheKey(string $uid): string
    {
        return 'setting.' . $uid;
    }

    protected static function booted(): void
    {
        static::saved(static function (Setting $setting): void {
            static::clearCache($setting->uid);
        });
    }
}
