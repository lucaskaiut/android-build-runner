<?php

namespace App\Services;

use App\Enums\BuildStatus;
use App\Enums\RunnerStatus;
use App\Models\Build;
use App\Models\Runner;
use Illuminate\Support\Facades\DB;

class BuildClaimService
{
    public function __construct(
        private readonly LogIngestService $logs,
    ) {}

    /**
     * Atomically claims the oldest queued build. MySQL row locking with
     * SKIP LOCKED guarantees two runners never receive the same build.
     */
    public function claim(Runner $runner): ?Build
    {
        return DB::transaction(function () use ($runner): ?Build {
            /** @var Build|null $build */
            $build = Build::query()
                ->where('status', BuildStatus::Queued->value)
                ->orderBy('queued_at')
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            if ($build === null) {
                return null;
            }

            $build->forceFill([
                'status' => BuildStatus::Preparing,
                'runner_id' => $runner->id,
                'claimed_at' => now(),
                'last_heartbeat_at' => now(),
                'lease_expires_at' => now()->addSeconds((int) config('builds.lease_seconds')),
            ])->save();

            $runner->forceFill([
                'status' => RunnerStatus::Online,
                'current_build_id' => $build->id,
                'last_heartbeat_at' => now(),
            ])->save();

            $this->logs->appendSystem($build, sprintf(
                'Build reivindicada pelo runner "%s" (lease de %ds).',
                $runner->name,
                (int) config('builds.lease_seconds'),
            ));

            return $build->refresh();
        });
    }
}
