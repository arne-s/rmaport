<?php

namespace App\Settings;

use App\Enums\PaymentTerms;
use App\Settings\Definitions\DurationTimeSetting;
use App\Settings\Definitions\EnabledDisabledSetting;
use App\Settings\Definitions\ExactPaymentConditionByTypeSetting;
use App\Settings\Definitions\ExactPaymentConditionSetting;
use App\Settings\Definitions\IntegerDaysSetting;
use App\Settings\Definitions\IntegerHoursSetting;
use App\Settings\Definitions\PaymentTermsSetting;

final class SettingsDefaults
{
    private const SEGMENTS = [
        'b2c' => 'Particulier',
        'b2b' => 'B2B',
    ];

    private const SUBTYPES = [
        'unit' => 'Unit',
        'part' => 'Onderdeel',
        'service' => 'Service / Onderhoud',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $indexedRows = null;

    /**
     * @return list<array{uid: string, class: class-string, name: string, description: string, value: mixed, sort: int}>
     */
    public static function rows(): array
    {
        return array_merge(
            self::paymentTermsMatrixRows(),
            self::exactConditionServiceB2cRow(),
            self::exactConditionByTypeRows(),
            self::mailDelayRows(),
            self::paymentVerifyRows(),
            self::invoiceReminderRows(),
        );
    }

    /**
     * @return array{uid: string, class: class-string, name: string, description: string, value: mixed, sort: int}|null
     */
    public static function rowForUid(string $uid): ?array
    {
        self::ensureIndexed();

        return self::$indexedRows[$uid] ?? null;
    }

    public static function defaultValue(string $uid): mixed
    {
        return self::rowForUid($uid)['value'] ?? null;
    }

    public static function definitionClassForUid(string $uid): ?string
    {
        return self::rowForUid($uid)['class'] ?? null;
    }

    /**
     * @return list<array{uid: string, class: class-string, name: string, description: string, value: mixed, sort: int}>
     */
    private static function paymentTermsMatrixRows(): array
    {
        $defaults = [
            'unit' => [
                'b2c' => PaymentTerms::Split50_50->value,
                'b2b' => PaymentTerms::Advance100->value,
            ],
            'part' => [
                'b2c' => PaymentTerms::Advance100->value,
                'b2b' => PaymentTerms::PostpayShipping->value,
            ],
            'service' => [
                'b2c' => PaymentTerms::DirectService->value,
                'b2b' => PaymentTerms::PostpayService->value,
            ],
        ];

        $rows = [];
        $sortBase = ['unit' => 100, 'part' => 104, 'service' => 108];
        $segmentOffset = ['b2c' => 0, 'b2b' => 1];

        foreach ($defaults as $subtype => $segments) {
            foreach ($segments as $segment => $value) {
                $rows[] = [
                    'uid' => "payment.{$subtype}.{$segment}.payment_terms",
                    'class' => PaymentTermsSetting::class,
                    'name' => self::SEGMENTS[$segment],
                    'description' => '',
                    'value' => $value,
                    'sort' => $sortBase[$subtype] + $segmentOffset[$segment],
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{uid: string, class: class-string, name: string, description: string, value: mixed, sort: int}>
     */
    private static function exactConditionServiceB2cRow(): array
    {
        return [
            [
                'uid' => 'payment.service.b2c.exact_payment_condition',
                'class' => ExactPaymentConditionSetting::class,
                'name' => self::SEGMENTS['b2c'],
                'description' => '',
                'value' => '1D',
                'sort' => 112,
            ],
        ];
    }

    /**
     * @return list<array{uid: string, class: class-string, name: string, description: string, value: mixed, sort: int}>
     */
    private static function exactConditionByTypeRows(): array
    {
        $defaults = [
            'b2c' => '14',
            'b2b' => '30',
        ];

        $rows = [];
        $sort = 300;

        foreach ($defaults as $segment => $value) {
            $rows[] = [
                'uid' => "exact_payment_condition_by_type.{$segment}",
                'class' => ExactPaymentConditionByTypeSetting::class,
                'name' => self::SEGMENTS[$segment],
                'description' => '',
                'value' => $value,
                'sort' => $sort++,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{uid: string, class: class-string, name: string, description: string, value: mixed, sort: int}>
     */
    private static function mailDelayRows(): array
    {
        $rows = [
            [
                'uid' => 'mail.deposit_invoice_mail_delay_seconds',
                'name' => 'Wachttijd',
                'description' => 'Wachttijd (uu:mm) na aanmaken voordat de e-mail wordt verzonden.',
                'value' => 4 * 3600,
                'sort' => 400,
            ],
            [
                'uid' => 'mail.full_invoice_delay',
                'name' => 'B2B',
                'description' => 'Wachttijd (uu:mm) na aanmaken van de slotfactuur voordat de factuurmail wordt verzonden.',
                'value' => 4 * 3600,
                'sort' => 401,
            ],
            [
                'uid' => 'mail.invoice_mail_delay_seconds',
                'name' => 'Standaard',
                'description' => 'Wachttijd (uu:mm) na aanmaken van de slotfactuur voordat de factuurmail wordt verzonden.',
                'value' => 0,
                'sort' => 402,
            ],
            [
                'uid' => 'mail.dealer_invoice_mail_delay_seconds',
                'name' => 'Dealer',
                'description' => 'Wachttijd (uu:mm) na aanmaken van de slotfactuur voordat de factuurmail wordt verzonden.',
                'value' => 48 * 3600,
                'sort' => 403,
            ],
            [
                'uid' => 'mail.part_b2c_mail_delay_seconds',
                'name' => 'Particulier',
                'description' => 'Wachttijd (uu:mm) na aanmaken van de slotfactuur voordat de factuurmail wordt verzonden.',
                'value' => 4 * 3600,
                'sort' => 404,
            ],
            [
                'uid' => 'mail.part_b2b_mail_delay_seconds',
                'name' => 'B2B',
                'description' => 'Wachttijd (uu:mm) na aanmaken van de slotfactuur voordat de factuurmail wordt verzonden.',
                'value' => 0,
                'sort' => 405,
            ],
            [
                'uid' => 'mail.part_dealer_mail_delay_seconds',
                'name' => 'Dealer',
                'description' => 'Wachttijd (uu:mm) na aanmaken van de slotfactuur voordat de factuurmail wordt verzonden.',
                'value' => 48 * 3600,
                'sort' => 406,
            ],
            [
                'uid' => 'mail.service_b2c_invoice_mail_delay_seconds',
                'name' => 'Particulier',
                'description' => 'Wachttijd (uu:mm) na aanmaken van de slotfactuur voordat de factuurmail wordt verzonden.',
                'value' => 0,
                'sort' => 407,
            ],
            [
                'uid' => 'mail.service_dealer_invoice_mail_delay_seconds',
                'name' => 'Dealer',
                'description' => 'Wachttijd (uu:mm) na aanmaken van de slotfactuur voordat de factuurmail wordt verzonden.',
                'value' => 4 * 3600,
                'sort' => 408,
            ],
        ];

        return array_map(static fn (array $row): array => [
            ...$row,
            'class' => DurationTimeSetting::class,
        ], $rows);
    }

    /**
     * @return list<array{uid: string, class: class-string, name: string, description: string, value: mixed, sort: int}>
     */
    private static function paymentVerifyRows(): array
    {
        return [
            [
                'uid' => 'mail.payment_verify_hours_before_delivery',
                'class' => IntegerHoursSetting::class,
                'name' => 'Max uren voor levering',
                'description' => 'Stuur payment-verify mail wanneer de leverafspraak binnen dit aantal uren valt (bovenkant venster).',
                'value' => 48,
                'sort' => 420,
            ],
            [
                'uid' => 'mail.payment_verify_min_hours_until_delivery',
                'class' => IntegerHoursSetting::class,
                'name' => 'Min uren voor levering',
                'description' => 'Onderkant venster — mail niet meer sturen als levering binnen dit aantal uren is.',
                'value' => 6,
                'sort' => 421,
            ],
        ];
    }

    /**
     * @return list<array{uid: string, class: class-string, name: string, description: string, value: mixed, sort: int}>
     */
    private static function invoiceReminderRows(): array
    {
        $segmentRows = [];
        $sort = 494;

        foreach (self::SEGMENTS as $segmentKey => $segmentLabel) {
            $segmentRows[] = [
                'uid' => 'mail.invoice_reminders.' . $segmentKey . '.enabled',
                'class' => EnabledDisabledSetting::class,
                'name' => $segmentLabel . '*',
                'description' => '',
                'value' => EnabledDisabledSetting::ENABLED,
                'sort' => $sort,
            ];
            $sort++;
        }

        return [
            ...$segmentRows,
            [
                'uid' => 'mail.invoice_first_reminder_days_after_due',
                'class' => IntegerDaysSetting::class,
                'name' => '1e herinnering na vervaldatum',
                'description' => 'Aantal dagen na de betaaldatum (vervaldatum) voordat de 1e betalingsherinnering wordt verstuurd.',
                'value' => 0,
                'sort' => 500,
            ],
            [
                'uid' => 'mail.invoice_second_reminder_days_after_first',
                'class' => IntegerDaysSetting::class,
                'name' => '2e herinnering na 1e',
                'description' => 'Aantal dagen na de 1e herinnering voordat de 2e betalingsherinnering wordt verstuurd.',
                'value' => 7,
                'sort' => 501,
            ],
        ];
    }

    private static function quotedSegment(string $segment): string
    {
        return '"' . self::SEGMENTS[$segment] . '"';
    }

    private static function quotedSubtype(string $subtype): string
    {
        return '"' . self::SUBTYPES[$subtype] . '"';
    }

    private static function ensureIndexed(): void
    {
        if (self::$indexedRows !== null) {
            return;
        }

        self::$indexedRows = [];

        foreach (self::rows() as $row) {
            self::$indexedRows[$row['uid']] = $row;
        }
    }
}
