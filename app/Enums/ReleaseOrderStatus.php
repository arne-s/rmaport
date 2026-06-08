<?php

namespace App\Enums;

enum ReleaseOrderStatus: string
{
    case Initial = 'initial';
    case Purchased = 'purchased';
    case PartiallyConfirmed = 'partially_confirmed';
    case Confirmed = 'confirmed';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Initial => 'Niet opgeslagen',
            self::Purchased => 'Afgeroepen',
            self::PartiallyConfirmed => 'Gedeeltelijk bevestigd',
            self::Confirmed => 'Bevestigd',
            self::PartiallyDelivered => 'Gedeeltelijk geleverd',
            self::Delivered => 'Geleverd',
            self::Cancelled => 'Geannuleerd',
            default => null,
        };
    }

    public static function labels(): array
    {
        return array_reduce(self::cases(), function ($carry, $item) {
            $carry[$item->value] = $item->getLabel();
            return $carry;
        }, []);
    }

    /**
     * Statuses visible in dropdowns/filters: excludes internal-only statuses (Initial).
     */
    public static function visibleStatuses(): array
    {
        return array_reduce(
            array_filter(self::cases(), fn(self $s) => !in_array($s, [self::Initial, self::Cancelled], true)),
            function (array $carry, self $item): array {
                $carry[$item->value] = $item->getLabel();
                return $carry;
            },
            []
        );
    }

    /**
     * Per status value: whether the user may pick this status in UI dropdowns.
     * PartiallyConfirmed and PartiallyDelivered are derived from order lines.
     *
     * @return array<string, bool>
     */
    public static function selectableStatuses(): array
    {
        $result = [];
        foreach (self::cases() as $case) {
            $result[$case->value] = !in_array($case, [self::PartiallyConfirmed, self::PartiallyDelivered, self::Cancelled], true);
        }

        return $result;
    }

    /**
     * Line status to apply when this release order status is set on the header (null = do not bulk-sync lines).
     */
    public function toOrderProductStatus(): ?OrderProductStatus
    {
        return match ($this) {
            self::Purchased => OrderProductStatus::Sent,
            self::Confirmed => OrderProductStatus::Confirmed,
            self::Delivered => OrderProductStatus::Delivered,
            default => null,
        };
    }

    public static function getCategory(self $status): string
    {
        return match ($status) {
            self::Purchased,
            self::PartiallyConfirmed,
            self::Confirmed => 'Afroep',
            self::PartiallyDelivered,
            self::Delivered => 'Op locatie',
            self::Cancelled => 'Geannuleerd',
            default => 'Onbekend',
        };
    }

    public static function allWithCategories(): array
    {
        return array_reduce(self::cases(), function ($carry, $item) {
            $carry[$item->value] = [
                'status' => $item,
                'label' => $item->getLabel(),
                'category' => self::getCategory($item),
            ];
            return $carry;
        }, []);
    }

    /**
     * All with categories for dropdowns: excludes internal-only statuses (Initial).
     */
    public static function allWithCategoriesForSelect(): array
    {
        return array_reduce(
            array_filter(self::cases(), fn(self $s) => !in_array($s, [self::Initial], true)),
            function (array $carry, self $item): array {
                $carry[$item->value] = [
                    'status' => $item,
                    'label' => $item->getLabel(),
                    'category' => self::getCategory($item),
                ];
                return $carry;
            },
            []
        );
    }
}
