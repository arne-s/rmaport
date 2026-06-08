<?php

namespace App\Enums;

enum OrderStatus: string
{
    // Passing
    case FittingDraft = 'fitting_draft';
    case FittingPlanned = 'fitting_planned';
    case FittingReady = 'fitting_ready';
    case FittingOnHold = 'fitting_on_hold';
    case FittingCancelled = 'fitting_cancelled';

    case Fitting = 'fitting';

    // Quote
    case QuoteDraft = 'quote_draft';
    case QuoteConcept = 'quote_concept';
    case QuoteSent = 'quote_sent';
    case QuoteCancelled = 'quote_rejected';
    case QuoteExpired = 'quote_expired';

    case Quote = 'quote';

    // Order
    case Order = 'order';
    case OrderDraft = 'order_concept';
    case OrderAudit = 'order_audit';
    case OrderApproved = 'order_approved';
    case OrderSent = 'order_sent';

    // Purchase
    case Purchase = 'purchase';
    case OrderAwaitingPurchase = 'order_awaiting_purchase';
    case PartiallyPurchased = 'partially_purchased';
    case Purchased = 'purchased';
    case PartiallyConfirmed = 'partially_confirmed';
    case PoConfirmed = 'po_confirmed';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';

    // Assembly
    case Assembly = 'assembly';
    case ReadyForAssembly = 'ready_for_assembly';
    case AssemblyPlanned = 'assembly_planned';
    case AssemblyOnHold = 'assembly_on_hold';
    case Assembled = 'assembled';

    // Delivery (legacy shipping values normalized via normalizeLegacyStatus)
    case ReadyForPickup = 'ready_for_pickup';
    case DeliveryPlanned = 'delivery_planned';
    case DeliveryOnHold = 'delivery_on_hold';
    case Delivery = 'delivery';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered = 'delivered';

    case Cancelled = 'cancelled';

    /** @var array<string, self> */
    private const LEGACY_STATUS_MAP = [
        'shipping' => self::Delivery,
        'ready_for_shipping' => self::DeliveryPlanned,
        'shipped' => self::Delivered,
    ];

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Fitting => 'Passing',
            self::FittingDraft => 'Concept',
            self::FittingPlanned => 'Ingepland',
            self::FittingReady => 'Afgerond',
            self::FittingOnHold => 'On Hold: Opnieuw inplannen',
            self::FittingCancelled => 'Geannuleerd',

            self::Quote => 'Offerte',
            self::QuoteDraft => 'Op te stellen',
            self::QuoteConcept => 'Concept',
            self::QuoteSent => 'Verzonden',
            self::QuoteCancelled => 'Geannuleerd',
            self::QuoteExpired => 'Verlopen',

            self::Order => 'Order',
            self::OrderDraft => 'Concept',
            self::OrderAudit => 'Adviseur controle',
            self::OrderApproved => 'Goedgekeurd',
            self::OrderSent => 'Verzonden',

            self::Purchase => 'Inkoop',
            self::OrderAwaitingPurchase => 'Klaar voor inkoop',
            self::PartiallyPurchased => 'Gedeeltelijk ingekocht',
            self::Purchased => 'Bestelling volledig ingekocht',
            self::PartiallyConfirmed => 'Gedeeltelijk bevestigd',
            self::PoConfirmed => 'Volledig bevestigd',
            self::PartiallyReceived => 'Gedeeltelijk geleverd',
            self::Received => 'Volledig geleverd',

            self::Assembly => 'Montage',
            self::ReadyForAssembly => 'Klaar voor montage',
            self::AssemblyPlanned => 'Ingepland',
            self::AssemblyOnHold => 'On Hold: Opnieuw inplannen',
            self::Assembled => 'Afgerond',

            self::ReadyForPickup => 'Klaar voor levering/verzending',
            self::DeliveryPlanned => 'Ingepland',
            self::DeliveryOnHold => 'On Hold: Opnieuw inplannen',
            self::Delivery => 'Levering/Verzending',
            self::PartiallyDelivered => 'Gedeeltelijk geleverd',
            self::Delivered => 'Geleverd/Verzonden',

            self::Cancelled => 'Geannuleerd',

            default => null,
        };
    }


    public static function normalizeLegacyStatus(string $value): ?self
    {
        if ($value === '') {
            return null;
        }

        if (isset(self::LEGACY_STATUS_MAP[$value])) {
            return self::LEGACY_STATUS_MAP[$value];
        }

        return self::tryFrom($value);
    }

    public static function getMainStatuses(): array
    {
        return [
            self::Fitting,
            self::Quote,
            self::Order,
            self::Purchase,
            self::Assembly,
            self::Delivery,
            self::Cancelled,
        ];
    }

    public static function getMainStatusNumber(self $status): int
    {
        $main = self::getMainStatusFor($status);

        return match ($main) {
            self::Fitting => 1,
            self::Quote => 2,
            self::Order => 3,
            self::Purchase => 4,
            self::Assembly => 5,
            self::Delivery => 6,
            self::Cancelled => 7,
            default => 0,
        };
    }

    public static function getSubStatusIndex(self $subStatus): int
    {
        $main = self::getMainStatusFor($subStatus);
        $subs = self::getSubStatuses($main);
        $index = array_search($subStatus, $subs, true);

        return $index === false ? 0 : (int) $index;
    }

    public static function formatSubStatusLabel(self $subStatus): string
    {
        $main = self::getMainStatusFor($subStatus);
        $subs = self::getSubStatuses($main);
        $label = $subStatus->getLabel() ?? $subStatus->value;

        if (count($subs) <= 1) {
            return $label;
        }

        $letter = chr(97 + self::getSubStatusIndex($subStatus));

        return "{$letter}. {$label}";
    }

    public static function getFlowCompletionStatus(OrderSubtype $subtype): self
    {
        return match ($subtype) {
            OrderSubtype::Service => self::Assembled,
            default => self::Delivered,
        };
    }

    /**
     * Given a main or sub status, return the corresponding main (head) status.
     */
    public static function getMainStatusFor(self $status): self
    {
        $mainStatuses = self::getMainStatuses();
        foreach ($mainStatuses as $main) {
            if ($main === $status) {
                return $main;
            }
            if (in_array($status, self::getSubStatuses($main), true)) {
                return $main;
            }
        }

        return $status;
    }

    public static function getSubStatuses(OrderStatus $status): array
    {
        return match ($status) {
            self::Fitting => [
                self::FittingDraft,
                self::FittingPlanned,
                self::FittingOnHold,
                self::FittingReady,
                self::FittingCancelled,
            ],
            self::Quote => [
                self::QuoteDraft,
                self::QuoteConcept,
                self::QuoteSent,
                self::QuoteCancelled,
                self::QuoteExpired,
            ],
            self::Order => [
                self::OrderDraft,
                self::OrderAudit,
                self::OrderApproved,
                self::OrderSent,
            ],
            self::Purchase => [
                self::OrderAwaitingPurchase,
                self::PartiallyPurchased,
                self::Purchased,
                self::PartiallyConfirmed,
                self::PoConfirmed,
                self::PartiallyReceived,
                self::Received,
            ],
            self::Assembly => [
                self::ReadyForAssembly,
                self::AssemblyPlanned,
                self::AssemblyOnHold,
                self::Assembled,
            ],
            self::Delivery => [
                self::ReadyForPickup,
                self::DeliveryPlanned,
                self::DeliveryOnHold,
                self::PartiallyDelivered,
                self::Delivered,
            ],
            self::Cancelled => [
                self::Cancelled,
            ],
            default => [],
        };
    }

    /**
     * Database values for orders.order_status in a workflow phase: substatuses plus the parent enum value when it is stored as-is (e.g. purchase, order, fitting).
     *
     * @return list<string>
     */
    public static function orderStatusColumnValuesForPhase(self $phase): array
    {
        $values = array_values(array_unique(array_map(
            fn (self $s): string => $s->value,
            self::getSubStatuses($phase)
        )));
        if (! in_array($phase->value, $values, true)) {
            $values[] = $phase->value;
        }

        return $values;
    }

    /**
     * Whether this status can be selected by the user in the order status dropdown.
     */
    public function isUserSelectable(): bool
    {
        return match ($this) {
            self::FittingCancelled,
            self::FittingReady,
            self::AssemblyPlanned,
            self::Assembled,
            self::OrderApproved => true,
            default => false,
        };
    }

    /**
     * Whether this status can be shown by the user in the order status dropdown.
     */
    public function isVisibleInSelect(): bool
    {
        return match ($this) {
            self::QuoteCancelled => false,
            default => true,
        };
    }

    /**
     * Whether this status can be selected in the dropdown when the current status is $current.
     * Used to enforce allowed transitions (e.g. FittingReady only from FittingPlanned).
     */
    public function canBeSelectedWhenCurrentIs(?OrderStatus $current, ?OrderSubtype $subtype = null): bool
    {
        return match ($this) {
            self::FittingReady => $current === self::FittingPlanned,
            self::OrderApproved => $current === self::OrderAudit,
            self::Assembled => $current === self::AssemblyPlanned,
            self::Delivered => $subtype === OrderSubtype::Part && in_array($current, [self::ReadyForPickup, self::DeliveryPlanned], true),
            default => true,
        };
    }

    public static function labels(): array
    {
        return array_reduce(self::cases(), function ($carry, $item) {
            $carry[$item->value] = $item->getLabel() ?? $item->value;

            return $carry;
        }, []);
    }

    public static function getCategory(self $status): string
    {
        return match ($status) {
            self::Fitting, self::FittingDraft, self::FittingPlanned, self::FittingOnHold, self::FittingReady, self::FittingCancelled => 'Passing',
            self::Quote, self::QuoteDraft, self::QuoteConcept, self::QuoteSent, self::QuoteCancelled, self::QuoteExpired => 'Offerte',
            self::Order, self::OrderDraft, self::OrderAudit, self::OrderApproved, self::OrderSent => 'Order',
            self::OrderAwaitingPurchase, self::PartiallyPurchased, self::Purchased, self::PartiallyConfirmed,
            self::Purchase, self::PoConfirmed, self::PartiallyReceived, self::Received => 'Inkoop',
            self::Assembly, self::ReadyForAssembly, self::AssemblyPlanned, self::AssemblyOnHold, self::Assembled => 'Montage',
            self::Delivery, self::ReadyForPickup, self::DeliveryPlanned, self::DeliveryOnHold, self::PartiallyDelivered, self::Delivered => 'Levering/Verzending',
            self::Cancelled => 'Geannuleerd',
            default => 'Onbekend',
        };
    }

    public static function allWithCategories(): array
    {
        return array_reduce(self::cases(), function (array $carry, self $item): array {
            $carry[$item->value] = [
                'status' => $item,
                'label' => $item->getLabel(),
                'category' => self::getCategory($item),
            ];

            return $carry;
        }, []);
    }

    /**
     * Format status for display. When the main status has only one substatus with the same label
     * (e.g. Geannuleerd), returns only that label once; otherwise "Hoofdstatus: Substatus".
     *
     * @param  bool  $withNumber  When true, prefix with main index (e.g. "7. Geannuleerd" or "4. Inkoop: Klaar voor inkoop").
     */
    public static function formatForDisplay(self $status, bool $withNumber = false): string
    {
        $mainStatus = self::getMainStatusFor($status);
        $mainIndex = self::getMainStatusNumber($status);

        if ($mainIndex === 0) {
            return $status->getLabel() ?? $status->value;
        }

        $mainLabel = $mainStatus->getLabel() ?? $mainStatus->value;
        $subLabel = $status->getLabel() ?? $status->value;
        $subs = self::getSubStatuses($mainStatus);

        $displayLabel = (count($subs) === 1 && $mainLabel === $subLabel)
            ? $mainLabel
            : (str_starts_with($subLabel, $mainLabel . ' ')
                ? $subLabel
                : $mainLabel . ': ' . $subLabel);

        return $withNumber ? sprintf('%d. %s', $mainIndex, $displayLabel) : $displayLabel;
    }

    /**
     * Format status as "1. Passing: Ingepland" (hoofdnummer. hoofdstatus: substatus).
     * For use on the Fittings page aanvraag-status column.
     */
    public static function formatWithMainIndexAndSubLabel(self $status): string
    {
        return self::formatForDisplay($status, true);
    }

    public static function shouldShowProductsBucketTab(?self $status): bool
    {
        if ($status === null) {
            return false;
        }

        $main = self::getMainStatusFor($status);

        return in_array($main, [
            self::Purchase,
            self::Assembly,
            self::Delivery,
        ], true);
    }

    public static function shouldShowAssemblyMontageTab(?self $status): bool
    {
        if ($status === null) {
            return false;
        }

        return self::getMainStatusFor($status) === self::Assembly;
    }

    /**
     * Tabs on order view (aanvraag): Artikelen/bucket — based on main status, not substatus.
     * Visible from Order phase (concept / adviseur / goedgekeurd) through later phases.
     */
    public static function shouldShowOrderViewProductsTab(?self $status): bool
    {
        if ($status === null) {
            return false;
        }

        $main = self::getMainStatusFor($status);

        return in_array($main, [
            self::Order,
            self::Purchase,
            self::Assembly,
            self::Delivery,
            self::Cancelled,
        ], true);
    }

    /**
     * Hoofdstatus Order: Artikelen/bucket-tab is alleen ter inzage (geen vinkjes, geen statuswijzigingen).
     */
    public static function locksProductTabInteractions(?self $status): bool
    {
        if ($status === null) {
            return false;
        }

        return self::getMainStatusFor($status) === self::Order;
    }

    public static function shouldShowOrderViewDeliveryTab(?self $status): bool
    {
        if ($status === null) {
            return false;
        }

        $main = self::getMainStatusFor($status);

        return in_array($main, [
            self::Assembly,
            self::Delivery,
            self::Cancelled,
        ], true);
    }

    /**
     * Tabs on order view: Montage — visible from assembly phase through delivery phase and when cancelled.
     */
    public static function shouldShowOrderViewAssemblyTab(?self $status): bool
    {
        if ($status === null) {
            return false;
        }

        $main = self::getMainStatusFor($status);

        return in_array($main, [
            self::Assembly,
            self::Delivery,
            self::Cancelled,
        ], true);
    }

    /**
     * Tabs on order view: Verzending — only for Part subtype orders, visible from delivery phase (formerly shipping) and when cancelled.
     */
    public static function shouldShowOrderViewShippingTab(?self $status): bool
    {
        if ($status === null) {
            return false;
        }

        $main = self::getMainStatusFor($status);

        return in_array($main, [
            self::Delivery,
            self::Cancelled,
        ], true);
    }
}
