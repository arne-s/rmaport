<?php

namespace App\Enums;

enum EmailTemplateAudience: string
{
    case Internal = 'internal';
    case External = 'external';
    case Both = 'both';

    public function getLabel(): string
    {
        return match ($this) {
            self::Internal => 'Intern',
            self::External => 'Extern',
            self::Both => 'Intern/Extern',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_reduce(self::cases(), function (array $carry, self $item): array {
            $carry[$item->value] = $item->getLabel();

            return $carry;
        }, []);
    }
}
