<?php

namespace App\Filament\Settings;

use App\Models\Setting;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

final class PaymentSettingsTab
{
    /**
     * @return list<array{title: string, from: int, to: int, columns?: int, dividerBeforeSort?: int|null}>
     */
    private const SECTIONS = [
        ['title' => 'Betalingsvoorwaarden Unit', 'from' => 100, 'to' => 103],
        ['title' => 'Betalingsvoorwaarden Onderdeel', 'from' => 104, 'to' => 107],
        ['title' => 'Betalingsvoorwaarden Service / Onderhoud', 'from' => 108, 'to' => 111],
        ['title' => 'Betalingsconditie Service / Onderhoud', 'from' => 112, 'to' => 112],
        ['title' => 'Standaard betalingsconditie klanttype', 'from' => 300, 'to' => 303],
        ['title' => 'Mailvertraging aanbetalingsfactuur', 'from' => 400, 'to' => 400],
        ['title' => 'Mailvertraging Unit', 'from' => 401, 'to' => 403],
        ['title' => 'Mailvertraging Onderdeel', 'from' => 404, 'to' => 406],
        ['title' => 'Mailvertraging Service / Onderhoud', 'from' => 407, 'to' => 408],
        ['title' => 'Factuurherinneringen', 'from' => 494, 'to' => 501, 'dividerBeforeSort' => 500],
    ];

    /**
     * @return list<Section>
     */
    public static function schema(): array
    {
        $settingSections = collect(self::SECTIONS)
            ->map(static function (array $section): ?Section {
                if (! self::hasSettingsInRange($section['from'], $section['to'])) {
                    return null;
                }

                return SettingForm::section(
                    $section['title'],
                    $section['from'],
                    $section['to'],
                    $section['columns'] ?? 1,
                    $section['dividerBeforeSort'] ?? null,
                );
            })
            ->filter()
            ->values()
            ->all();

        $uncategorizedSection = self::uncategorizedSection();

        if ($uncategorizedSection !== null) {
            $settingSections[] = $uncategorizedSection;
        }

        if ($settingSections === []) {
            return [];
        }

        return [
            Section::make('')
                ->extraAttributes(['class' => 'customerSection settingspage-payment-tab custom-form-design'])
                ->schema([
                    Grid::make(12)
                        ->schema([
                            Grid::make(1)
                                ->columnSpan(['default' => 12, 'lg' => 8])
                                ->schema($settingSections),
                        ]),
                ]),
        ];
    }

    private static function hasSettingsInRange(int $from, int $to): bool
    {
        return Setting::query()
            ->whereBetween('sort', [$from, $to])
            ->exists();
    }

    private static function uncategorizedSection(): ?Section
    {
        $knownRanges = collect(self::SECTIONS)
            ->map(static fn (array $section): array => ['from' => $section['from'], 'to' => $section['to']]);

        $settings = Setting::query()
            ->orderBy('sort')
            ->get()
            ->filter(static function (Setting $setting) use ($knownRanges): bool {
                return ! $knownRanges->contains(
                    static fn (array $range): bool => $setting->sort >= $range['from'] && $setting->sort <= $range['to'],
                );
            });

        if ($settings->isEmpty()) {
            return null;
        }

        return Section::make('Overige instellingen')
            ->extraAttributes(['class' => 'beheer-bedrijfsgegevensSection header-bedrijfsgegevens settingspage-payment-section'])
            ->schema([
                Grid::make(1)->schema(
                    $settings
                        ->map(static fn (Setting $setting) => $setting->toFormComponent('settings.' . $setting->uid))
                        ->all(),
                ),
            ]);
    }
}
