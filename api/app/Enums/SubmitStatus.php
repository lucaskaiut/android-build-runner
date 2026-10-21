<?php

namespace App\Enums;

enum SubmitStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando submit',
            self::Success => 'Submit concluído',
            self::Failed => 'Submit falhou',
            self::Skipped => 'Submit não executado',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
