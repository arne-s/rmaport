<?php

namespace App\Enums;

enum RmaAssessment: string
{
    case NotDefect = 'not_defect';
    case DefectRepair = 'defect_repair';
    case Return = 'return';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::NotDefect => 'Niet Defect',
            self::DefectRepair => 'Defect / Reparatie',
            self::Return => 'Retour',
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
}
