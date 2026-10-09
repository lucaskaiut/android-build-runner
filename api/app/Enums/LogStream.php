<?php

namespace App\Enums;

enum LogStream: string
{
    case Stdout = 'stdout';
    case Stderr = 'stderr';
    case System = 'system';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
