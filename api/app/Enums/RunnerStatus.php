<?php

namespace App\Enums;

enum RunnerStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::Disabled => 'Desativado',
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
