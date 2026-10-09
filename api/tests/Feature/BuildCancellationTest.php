<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\Runner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BuildCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_build_is_cancelled_immediately(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $build = Build::factory()->create();

        $this->postJson('/api/v1/builds/'.$build->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('build.status', 'cancelled');

        $build->refresh();
        $this->assertSame(BuildStatus::Cancelled, $build->status);
        $this->assertNotNull($build->finished_at);
        $this->assertSame(0, $build->duration_ms);
    }

    public function test_running_build_receives_a_pending_cancel_flag(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $runner = Runner::factory()->online()->create();
        $build = Build::factory()->running($runner->id)->create();

        $this->postJson('/api/v1/builds/'.$build->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('build.status', 'running')
            ->assertJsonPath('build.cancel_requested', true);

        $build->refresh();
        $this->assertSame(BuildStatus::Running, $build->status);
        $this->assertNotNull($build->cancel_requested_at);
    }

    public function test_runner_observes_cancellation_via_heartbeat_and_completes_as_cancelled(): void
    {
        $runner = Runner::factory()->withToken()->create();
        Sanctum::actingAs($runner, ['runner']);

        $build = Build::factory()->running($runner->id)->create([
            'cancel_requested_at' => now(),
        ]);

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/heartbeat')
            ->assertOk()
            ->assertJsonPath('control.cancel_requested', true);

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/complete', [
            'status' => 'cancelled',
            'error_message' => 'interrompida pelo operador',
        ])
            ->assertOk()
            ->assertJsonPath('build.status', 'cancelled');
    }

    public function test_runner_cannot_complete_as_cancelled_without_a_request(): void
    {
        $runner = Runner::factory()->withToken()->create();
        Sanctum::actingAs($runner, ['runner']);

        $build = Build::factory()->running($runner->id)->create();

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/complete', [
            'status' => 'cancelled',
        ])->assertStatus(409);

        $this->assertSame(BuildStatus::Running, $build->fresh()->status);
    }

    public function test_terminal_build_cannot_be_cancelled(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $build = Build::factory()->create(['status' => BuildStatus::Success, 'finished_at' => now()]);

        $this->postJson('/api/v1/builds/'.$build->id.'/cancel')->assertStatus(409);

        $this->assertSame(BuildStatus::Success, $build->fresh()->status);
    }

    public function test_cancel_request_is_idempotent(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $runner = Runner::factory()->online()->create();
        $build = Build::factory()->running($runner->id)->create();

        $this->postJson('/api/v1/builds/'.$build->id.'/cancel')->assertOk();
        $firstRequestedAt = $build->fresh()->cancel_requested_at;

        $this->postJson('/api/v1/builds/'.$build->id.'/cancel')->assertOk();

        $this->assertEquals(
            $firstRequestedAt->toIso8601String(),
            $build->fresh()->cancel_requested_at->toIso8601String(),
        );
    }
}
