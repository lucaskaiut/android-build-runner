<?php

namespace App\Models;

use App\Enums\RunnerStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'software_version',
    'protocol_version',
    'status',
    'current_build_id',
    'os_info',
    'metadata',
    'last_heartbeat_at',
    'token_rotated_at',
    'revoked_at',
])]
class Runner extends Authenticatable
{
    use HasApiTokens, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunnerStatus::class,
            'metadata' => 'array',
            'protocol_version' => 'integer',
            'last_heartbeat_at' => 'datetime',
            'token_rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function currentBuild(): BelongsTo
    {
        return $this->belongsTo(Build::class, 'current_build_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null || $this->status === RunnerStatus::Disabled;
    }

    public function isEffectivelyOnline(): bool
    {
        if ($this->isRevoked() || $this->last_heartbeat_at === null) {
            return false;
        }

        return $this->last_heartbeat_at->greaterThan(
            now()->subSeconds(config('builds.runner_offline_seconds')),
        );
    }

    public function effectiveStatus(): RunnerStatus
    {
        if ($this->isRevoked()) {
            return RunnerStatus::Disabled;
        }

        return $this->isEffectivelyOnline() ? RunnerStatus::Online : RunnerStatus::Offline;
    }
}
