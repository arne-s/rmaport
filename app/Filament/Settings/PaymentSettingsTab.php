<?php

namespace App\Filament\Settings;

use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

final class PaymentSettingsTab
{
    /**
     * @return list<Section>
     */
    public static function schema(): array
    {
        return [
            Section::make('')
                ->extraAttributes(['class' => 'customerSection settingspage-payment-tab custom-form-design'])
                ->schema([
                    Grid::make(12)
                        ->schema([
                            Grid::make(1)
                                ->columnSpan(['default' => 12, 'lg' => 8])
                                ->schema([
                                    SettingForm::section('Betalingsvoorwaarden Unit', 100, 103),
                                    SettingForm::section('Betalingsvoorwaarden Onderdeel', 104, 107),
                                    SettingForm::section('Betalingsvoorwaarden Service / Onderhoud', 108, 111),
                                    SettingForm::section('Betalingsconditie Service / Onderhoud', 112, 112),
                                    SettingForm::section('Standaard betalingsconditie klanttype', 300, 303),
                                    SettingForm::section('Mailvertraging aanbetalingsfactuur', 400, 400),
                                    SettingForm::section('Mailvertraging Unit', 401, 403),
                                    SettingForm::section('Mailvertraging Onderdeel', 404, 406),
                                    SettingForm::section('Mailvertraging Service / Onderhoud', 407, 408),
                                    SettingForm::section('Factuurherinneringen', 494, 501, dividerBeforeSort: 500),
                                ]),
                        ]),
                ]),
        ];
    }
}
