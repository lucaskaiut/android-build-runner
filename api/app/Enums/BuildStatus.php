<?php

namespace App\Enums;

enum BuildStatus: string
{
    case Queued = 'queued';
    case Preparing = 'preparing';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Na fila',
            self::Preparing => 'Preparando',
            self::Running => 'Executando',
            self::Success => 'Concluída',
            self::Failed => 'Falhou',
            self::Cancelled => 'Cancelada',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Success, self::Failed, self::Cancelled], true);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Queued, self::Preparing, self::Running], true);
    }

    /**
     * @return array<int, BuildStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [self::Preparing, self::Cancelled],
            self::Preparing => [self::Running, self::Failed, self::Cancelled],
            self::Running => [self::Success, self::Failed, self::Cancelled],
            self::Success, self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => ! $status->isTerminal()),
        ));
    }
}
