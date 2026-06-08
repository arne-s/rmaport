<?php

namespace App\Filament\Settings;

use App\Models\Setting;
use App\Settings\SettingsDefaults;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;

final class SettingForm
{
    public static function field(string $uid): Field
    {
        $setting = Setting::query()->where('uid', $uid)->first()
            ?? Setting::makeFromDefaults($uid);

        if ($setting === null) {
            throw new \InvalidArgumentException("Unknown setting uid [{$uid}].");
        }

        return $setting->toFormComponent('settings.' . $uid);
    }

    /**
     * @param  list<string>  $uids
     * @return list<Field>
     */
    public static function fields(array $uids): array
    {
        return array_map(static fn (string $uid): Field => self::field($uid), $uids);
    }

    /**
     * @return list<Field>
     */
    public static function fieldsForSortRange(int $from, int $to): array
    {
        $records = Setting::query()
            ->whereBetween('sort', [$from, $to])
            ->orderBy('sort')
            ->get();

        if ($records->isNotEmpty()) {
            return $records
                ->map(static fn (Setting $setting): Field => $setting->toFormComponent('settings.' . $setting->uid))
                ->all();
        }

        return collect(SettingsDefaults::rows())
            ->filter(static fn (array $row): bool => $row['sort'] >= $from && $row['sort'] <= $to)
            ->sortBy('sort')
            ->map(static fn (array $row): Field => self::field($row['uid']))
            ->values()
            ->all();
    }

    public static function grid(int $sortFrom, int $sortTo, int $columns = 1): Grid
    {
        return Grid::make($columns)
            ->extraAttributes(['class' => 'settingspage-payment-section beheer-bedrijfsgegevensSection'])
            ->schema(self::fieldsForSortRange($sortFrom, $sortTo));
    }

    public static function section(string $title, int $sortFrom, int $sortTo, int $columns = 1, ?int $dividerBeforeSort = null): Section
    {
        $schema = [];

        if ($dividerBeforeSort !== null && $dividerBeforeSort > $sortFrom && $dividerBeforeSort <= $sortTo) {
            $beforeFields = self::fieldsForSortRange($sortFrom, $dividerBeforeSort - 1);
            if ($beforeFields !== []) {
                $schema[] = Grid::make($columns)->schema($beforeFields);
            }

            $schema[] = Html::make('<hr class="settings-payment-section-divider" aria-hidden="true">');

            $afterFields = self::fieldsForSortRange($dividerBeforeSort, $sortTo);
            if ($afterFields !== []) {
                $schema[] = Grid::make($columns)->schema($afterFields);
            }
        } else {
            $schema[] = Grid::make($columns)
                ->schema(self::fieldsForSortRange($sortFrom, $sortTo));
        }

        return Section::make($title)
            ->extraAttributes(['class' => 'beheer-bedrijfsgegevensSection header-bedrijfsgegevens settingspage-payment-section'])
            ->schema($schema);
    }
}
