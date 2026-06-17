<?php

namespace App\Enums;

enum RmaStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Received = 'received';
    case InProgress = 'in_progress';
    case WaitingCustomer = 'waiting_customer';
    case WaitingSupplier = 'waiting_supplier';
    case Completed = 'completed';
    case Returned = 'returned';
    case Closed = 'closed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Draft => 'Aanvraag',
            self::Open => 'Open',
            self::Received => 'Ontvangen',
            self::InProgress => 'In behandeling',
            self::WaitingCustomer => 'Wacht op klant',
            self::WaitingSupplier => 'Wacht op leverancier',
            self::Completed => 'Afgerond',
            self::Returned => 'Retour',
            self::Closed => 'Gesloten',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'white',
            self::Open => 'gray',
            self::Received => 'info',
            self::InProgress => 'warning',
            self::WaitingCustomer, self::WaitingSupplier => 'warning',
            self::Completed => 'success',
            self::Returned => 'primary',
            self::Closed => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_reduce(self::cases(), function (array $carry, self $item): array {
            $carry[$item->value] = $item->getLabel() ?? $item->value;

            return $carry;
        }, []);
    }

    /**
     * @return array<int, self>
     */
    public static function overviewStatuses(): array
    {
        return [
            self::Draft,
            self::Open,
            self::Received,
            self::InProgress,
            self::WaitingCustomer,
            self::WaitingSupplier,
            self::Completed,
            self::Returned,
        ];
    }

    public function overviewSlug(): string
    {
        return $this->value;
    }

    public static function fromOverviewSlug(string $slug): ?self
    {
        return self::tryFrom($slug);
    }
}
