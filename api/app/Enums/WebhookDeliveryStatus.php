<?php

namespace App\Enums;

enum WebhookDeliveryStatus: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
    case Rejected = 'rejected';
    case Ignored = 'ignored';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Aceito',
            self::Duplicate => 'Duplicado',
            self::Rejected => 'Rejeitado',
            self::Ignored => 'Ignorado',
            self::Error => 'Erro',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
