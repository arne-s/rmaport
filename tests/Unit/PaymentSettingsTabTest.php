<?php

use App\Enums\PaymentTerms;
use App\Filament\Settings\PaymentSettingsTab;
use App\Filament\Settings\SettingForm;
use App\Models\Setting;
use App\Settings\Definitions\PaymentTermsSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(Tests\TestCase::class, DatabaseTransactions::class);

function createPaymentSetting(string $uid, string $name, int $sort): Setting
{
    return Setting::query()->create([
        'uid' => $uid,
        'class' => PaymentTermsSetting::class,
        'name' => $name,
        'description' => '',
        'value' => PaymentTerms::Advance100->value,
        'sort' => $sort,
    ]);
}

it('returns no payment tab sections when no settings exist', function (): void {
    expect(PaymentSettingsTab::schema())->toBe([]);
});

it('only renders sort ranges that exist in the database', function (): void {
    createPaymentSetting('payment.part.b2c.payment_terms', 'Particulier', 104);
    createPaymentSetting('payment.part.dealer.payment_terms', 'Dealer', 105);

    expect(PaymentSettingsTab::schema())->toHaveCount(1)
        ->and(SettingForm::fieldsForSortRange(100, 103))->toBe([])
        ->and(SettingForm::fieldsForSortRange(104, 107))->toHaveCount(2)
        ->and(SettingForm::section('Betalingsvoorwaarden Unit', 100, 103))->toBeNull()
        ->and(SettingForm::section('Betalingsvoorwaarden Onderdeel', 104, 107))->not->toBeNull();
});

it('loads form state only from database settings', function (): void {
    createPaymentSetting('payment.part.b2c.payment_terms', 'Particulier', 104);

    expect(Setting::allAsFlatFormState())->toHaveCount(1)
        ->and(Setting::allAsFlatFormState())->toHaveKey('payment.part.b2c.payment_terms');
});
