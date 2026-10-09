<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Enums\RunnerStatus;
use App\Models\Build;
use App\Models\BuildProfile;
use App\Models\Runner;
use App\Services\BuildLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BuildRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_reaper_fails_builds_with_expired_lease_without_requeueing(): void
    {
        $runner = Runner::factory()->online()->create();
        $build = Build::factory()->running($runner->id)->create([
            'lease_expires_at' => now()->subMinutes(5),
        ]);
        $runner->forceFill(['current_build_id' => $build->id])->save();

        $result = app(BuildLifecycleService::class)->reap();

        $this->assertSame(1, $result['expired']);

        $build->refresh();
        $this->assertSame(BuildStatus::Failed, $build->status);
        $this->assertStringContainsString('lease', (string) $build->error_message);
        $this->assertNotNull($build->finished_at);

        // Never silently requeued.
        $this->assertNotSame(BuildStatus::Queued, $build->status);

        $this->assertNull($runner->fresh()->current_build_id);
    }

    public function test_reaper_ignores_builds_with_a_valid_lease(): void
    {
        $runner = Runner::factory()->online()->create();
        $build = Build::factory()->running($runner->id)->create([
            'lease_expires_at' => now()->addMinutes(3),
        ]);

        $result = app(BuildLifecycleService::class)->reap();

        $this->assertSame(0, $result['expired']);
        $this->assertSame(BuildStatus::Running, $build->fresh()->status);
    }

    public function test_reaper_is_idempotent(): void
    {
        $runner = Runner::factory()->online()->create();
        $build = Build::factory()->running($runner->id)->create([
            'lease_expires_at' => now()->subMinutes(5),
        ]);

        $service = app(BuildLifecycleService::class);
        $service->reap();
        $firstFinishedAt = $build->fresh()->finished_at;

        $result = $service->reap();

        $this->assertSame(0, $result['expired']);
        $this->assertEquals($firstFinishedAt->toIso8601String(), $build->fresh()->finished_at->toIso8601String());
    }

    public function test_runner_marked_offline_after_missing_heartbeats(): void
    {
        $runner = Runner::factory()->online()->create([
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);

        $result = app(BuildLifecycleService::class)->reap();

        $this->assertSame(1, $result['runners_offline']);
        $this->assertSame(RunnerStatus::Offline, $runner->fresh()->status);
        $this->assertFalse($runner->fresh()->isEffectivelyOnline());
    }

    public function test_late_completion_after_reaper_does_not_change_terminal_state(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $build = Build::factory()->running($runner->id)->create([
            'lease_expires_at' => now()->subMinutes(5),
        ]);

        app(BuildLifecycleService::class)->reap();
        $this->assertSame(BuildStatus::Failed, $build->fresh()->status);

        Sanctum::actingAs($runner, ['runner']);

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/complete', [
            'status' => 'success',
            'exit_code' => 0,
        ])->assertStatus(409);

        $this->assertSame(BuildStatus::Failed, $build->fresh()->status);
    }

    public function test_hard_timeout_fails_build_and_requests_runner_cancel(): void
    {
        $runner = Runner::factory()->online()->create();
        $profile = BuildProfile::factory()->create(['timeout_seconds' => 30]);

        $build = Build::factory()->running($runner->id)->create([
            'project_id' => $profile->project_id,
            'build_profile_id' => $profile->id,
            'started_at' => now()->subMinutes(10),
            'claimed_at' => now()->subMinutes(10),
            'lease_expires_at' => now()->addMinutes(5), // runner is still heartbeating
        ]);
        $runner->forceFill(['current_build_id' => $build->id])->save();

        $result = app(BuildLifecycleService::class)->reap();

        $this->assertSame(1, $result['timed_out']);

        $build->refresh();
        $this->assertSame(BuildStatus::Failed, $build->status);
        $this->assertStringContainsString('timeout', mb_strtolower((string) $build->error_message));
        $this->assertNotNull($build->cancel_requested_at, 'O runner é instruído a interromper o processo.');
        $this->assertNull($runner->fresh()->current_build_id);
    }
}
