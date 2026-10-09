<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /**
     * Registers a sensitive operation. Callers must never pass secrets in
     * $meta - only identifiers and non-sensitive values.
     *
     * @param  array<string, mixed>  $meta
     */
    public function log(?User $user, string $action, ?Model $subject = null, array $meta = []): void
    {
        AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'meta' => $meta === [] ? null : $meta,
            'ip' => request()->ip(),
        ]);
    }
}
