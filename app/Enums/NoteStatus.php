<?php

namespace App\Enums;

enum NoteStatus: string
{
    case Open = 'open';
    case Ongoing = 'ongoing';
    case Completed = 'completed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Ongoing => 'Ongoing',
            self::Completed => 'Afgerond',
            default => null,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Ongoing => 'warning',
            self::Completed => 'info',
            default => 'gray',
        };
    }

    public static function labels(): array
    {
        return [
            self::Open->value => self::Open->getLabel(),
            self::Ongoing->value => self::Ongoing->getLabel(),
            self::Completed->value => self::Completed->getLabel(),
        ];
    }
}
