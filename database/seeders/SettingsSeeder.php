<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Settings\SettingsDefaults;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = SettingsDefaults::rows();
        $validUids = collect($rows)->pluck('uid');

        Setting::query()->whereNotIn('uid', $validUids)->delete();

        foreach ($rows as $row) {
            $existing = Setting::query()->where('uid', $row['uid'])->first();

            if ($existing === null) {
                $serializedValue = null;

                if ($row['value'] !== null && $row['value'] !== '') {
                    $temporary = Setting::makeFromDefaults($row['uid']);
                    $serializedValue = $temporary?->definition()->serialize($row['value']);
                }

                Setting::query()->create([
                    'uid' => $row['uid'],
                    'class' => $row['class'],
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'value' => $serializedValue,
                    'sort' => $row['sort'],
                ]);

                continue;
            }

            $updates = [
                'class' => $row['class'],
                'name' => $row['name'],
                'description' => $row['description'],
                'sort' => $row['sort'],
            ];

            if ($existing->value === null && $row['value'] !== null && $row['value'] !== '') {
                $temporary = Setting::makeFromDefaults($row['uid']);
                $updates['value'] = $temporary?->definition()->serialize($row['value']);
            }

            $existing->update($updates);
        }

        Setting::clearCache();
    }
}
