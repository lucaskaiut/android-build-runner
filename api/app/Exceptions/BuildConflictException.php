<?php

namespace App\Exceptions;

use App\Enums\BuildStatus;
use RuntimeException;

class BuildConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function notOwnedByRunner(): self
    {
        return new self('Esta build não está atribuída a este runner.');
    }

    public static function invalidState(BuildStatus $status, string $reason): self
    {
        return new self($reason, ['current_status' => $status->value, 'status_label' => $status->label()]);
    }
}
