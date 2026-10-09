<?php

namespace App\Enums;

enum BuildSource: string
{
    case Manual = 'manual';
    case Api = 'api';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Api => 'API',
            self::Webhook => 'Webhook',
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
