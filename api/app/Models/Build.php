<?php

namespace App\Models;

use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Enums\SubmitStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'project_id',
    'build_profile_id',
    'requested_by_user_id',
    'source',
    'branch',
    'commit_sha',
    'status',
    'submit_status',
    'submit_message',
    'submit_version_code',
    'submitted_at',
    'runner_id',
    'parameters',
    'queued_at',
    'claimed_at',
    'started_at',
    'finished_at',
    'duration_ms',
    'exit_code',
    'error_message',
    'lease_expires_at',
    'last_heartbeat_at',
    'cancel_requested_at',
    'webhook_delivery_id',
])]
class Build extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => BuildSource::class,
            'status' => BuildStatus::class,
            'submit_status' => SubmitStatus::class,
            'submitted_at' => 'datetime',
            'parameters' => 'array',
            'queued_at' => 'datetime',
            'claimed_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'duration_ms' => 'integer',
            'exit_code' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(BuildProfile::class, 'build_profile_id');
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(Runner::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function webhookDelivery(): BelongsTo
    {
        return $this->belongsTo(GithubWebhookDelivery::class, 'webhook_delivery_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BuildLog::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(BuildArtifact::class);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function canBeCancelled(): bool
    {
        return $this->status->canBeCancelled();
    }

    public function isCancelRequested(): bool
    {
        return $this->cancel_requested_at !== null;
    }

    /**
     * Duration based on persisted timestamps, never on a frontend counter.
     */
    public function computedDurationMs(): ?int
    {
        if ($this->duration_ms !== null) {
            return $this->duration_ms;
        }

        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInMilliseconds($this->finished_at ?? now(), true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', BuildStatus::activeValues());
    }

    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BuildStatus::Success->value,
            BuildStatus::Failed->value,
            BuildStatus::Cancelled->value,
        ]);
    }

    public function scopeWithExpiredLease(Builder $query): Builder
    {
        return $query
            ->active()
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now());
    }
}
