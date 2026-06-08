<?php
namespace App\Enums;

enum MailLogStatus: string
{
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Sending => 'In afwachting',
            self::Sent    => 'Verzonden',
            self::Failed  => 'Mislukt',
        };
    }

    public static function labels(): array
    {
        return array_reduce(self::cases(), function ($carry, $item) {
            $carry[$item->value] = $item->getLabel();
            return $carry;
        }, []);
    }
}
