<?php

namespace App\Enums;

enum DeliveryTime: string
{
    case ImmediateDelivery = 'immediate_delivery';
    case FiveDays = 'five_days';
    case OneWeek = 'one_week';
    case ThreeWeeks = 'three_weeks';
    case WithNewChair = 'with_new_chair';
    case WithUnit = 'with_unit';
    case ThirteenWeeks = 'thirteen_weeks';
    case FifteenWeeks = 'fifteen_weeks';
    case InConsultationWithCustomer = 'in_consultation_with_customer';
    case AfterInvoicePayment = 'after_invoice_payment';

    public function getLabel(): string
    {
        return match ($this) {
            self::ImmediateDelivery          => 'Direct geleverd',
            self::FiveDays                   => '5 dagen na opdracht',
            self::OneWeek                    => '1 week na opdracht',
            self::ThreeWeeks                 => '3 weken na opdracht',
            self::WithNewChair               => 'Samen met nieuwe stoel',
            self::WithUnit                   => 'Meeleveren met unit',
            self::ThirteenWeeks              => 'ca. 13 weken na opdracht, aflevering',
            self::FifteenWeeks               => 'ca. 15 weken na opdracht, aflevering',
            self::InConsultationWithCustomer => 'In overleg met klant',
            self::AfterInvoicePayment        => 'Na betaling factuur',
        };
    }

    public static function options(): array
    {
        return array_column(
            array_map(fn(self $case) => ['value' => $case->value, 'label' => $case->getLabel()], self::cases()),
            'label',
            'value'
        );
    }
}
