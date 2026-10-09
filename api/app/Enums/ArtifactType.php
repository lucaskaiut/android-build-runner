<?php

namespace App\Enums;

enum ArtifactType: string
{
    case Apk = 'apk';
    case Aab = 'aab';
    case Other = 'other';

    public static function fromFilename(string $filename): self
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'apk' => self::Apk,
            'aab' => self::Aab,
            default => self::Other,
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
