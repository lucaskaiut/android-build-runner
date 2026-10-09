<?php

namespace Database\Factories;

use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\BuildProfile;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Build>
 */
class BuildFactory extends Factory
{
    protected $model = Build::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'build_profile_id' => function (array $attributes) {
                return BuildProfile::factory()->create(['project_id' => $attributes['project_id']])->id;
            },
            'requested_by_user_id' => null,
            'source' => BuildSource::Manual,
            'branch' => 'main',
            'commit_sha' => null,
            'status' => BuildStatus::Queued,
            'runner_id' => null,
            'parameters' => null,
            'queued_at' => now(),
            'lease_expires_at' => null,
        ];
    }

    public function preparing(int $runnerId): static
    {
        return $this->state(fn () => [
            'status' => BuildStatus::Preparing,
            'runner_id' => $runnerId,
            'claimed_at' => now(),
            'last_heartbeat_at' => now(),
            'lease_expires_at' => now()->addSeconds(180),
        ]);
    }

    public function running(int $runnerId): static
    {
        return $this->state(fn () => [
            'status' => BuildStatus::Running,
            'runner_id' => $runnerId,
            'claimed_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
            'last_heartbeat_at' => now(),
            'lease_expires_at' => now()->addSeconds(180),
            'commit_sha' => str_repeat('a', 40),
        ]);
    }
}
