<?php

namespace App\Enums;

enum SubmitStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando submit',
            self::Processing => 'Enviando para a loja',
            self::Success => 'Submit concluído',
            self::Failed => 'Submit falhou',
            self::Skipped => 'Submit não executado',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Success, self::Failed, self::Skipped], true);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
