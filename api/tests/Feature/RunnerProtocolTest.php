<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Enums\SubmitStatus;
use App\Models\Build;
use App\Models\BuildProfile;
use App\Models\Project;
use App\Models\Runner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RunnerProtocolTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRunner(Runner $runner): void
    {
        Sanctum::actingAs($runner, ['runner']);
    }

    public function test_runner_endpoints_require_a_valid_runner_token(): void
    {
        $this->postJson('/api/runner/v1/heartbeat', [])->assertUnauthorized();

        // A panel user session is not a runner token.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/runner/v1/heartbeat', [])->assertForbidden();
    }

    public function test_revoked_runner_is_rejected(): void
    {
        $runner = Runner::factory()->disabled()->withToken()->create();
        $this->actingAsRunner($runner);

        $this->postJson('/api/runner/v1/heartbeat', [])->assertForbidden();
    }

    public function test_heartbeat_updates_runner_state(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $this->postJson('/api/runner/v1/heartbeat', [
            'software_version' => '1.2.3',
            'protocol_version' => 1,
            'os_info' => 'Windows 11 build 26100',
        ])
            ->assertOk()
            ->assertJsonPath('runner.status', 'online')
            ->assertJsonPath('abort_current_build', false);

        $runner->refresh();
        $this->assertSame('1.2.3', $runner->software_version);
        $this->assertSame(\App\Enums\RunnerStatus::Online, $runner->status);
        $this->assertNotNull($runner->last_heartbeat_at);
    }

    public function test_protocol_version_mismatch_is_rejected(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $this->postJson('/api/runner/v1/heartbeat', ['protocol_version' => 99])
            ->assertStatus(426);
    }

    public function test_claim_returns_null_when_queue_is_empty(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $this->postJson('/api/runner/v1/builds/claim')
            ->assertOk()
            ->assertJsonPath('build', null);
    }

    public function test_claim_moves_build_to_preparing_and_returns_execution_payload(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $project = Project::factory()->create([
            'repo_url' => 'git@github.com:acme/app.git',
            'working_directory' => 'apps/mobile',
            'webhook_secret' => 'super-secret-value',
        ]);
        $profile = BuildProfile::factory()->withAutoSubmit([
            'package_name' => 'com.example.app',
            'track' => 'alpha',
        ])->create([
            'project_id' => $project->id,
            'build_script' => 'Write-Output "build"',
            'env' => ['APP_VARIANT' => 'preview'],
            'artifact_patterns' => ['*.apk'],
        ]);
        $build = Build::factory()->create([
            'project_id' => $project->id,
            'build_profile_id' => $profile->id,
            'branch' => 'main',
        ]);

        $response = $this->postJson('/api/runner/v1/builds/claim')->assertOk();

        $response
            ->assertJsonPath('build.id', $build->id)
            ->assertJsonPath('build.project.repo_url', 'git@github.com:acme/app.git')
            ->assertJsonPath('build.profile.env.APP_VARIANT', 'preview')
            ->assertJsonPath('build.profile.auto_submit', true)
            ->assertJsonPath('build.profile.submit_config.package_name', 'com.example.app')
            ->assertJsonPath('build.profile.submit_config.track', 'alpha')
            ->assertJsonPath('build.script', 'Write-Output "build"')
            ->assertJsonPath('build.working_directory', 'apps/mobile')
            ->assertJsonPath('build.lease_seconds', (int) config('builds.lease_seconds'));

        // Never expose the webhook secret to the runner.
        $this->assertStringNotContainsString('super-secret-value', $response->getContent());

        $build->refresh();
        $this->assertSame(BuildStatus::Preparing, $build->status);
        $this->assertSame($runner->id, $build->runner_id);
        $this->assertNotNull($build->claimed_at);
        $this->assertNotNull($build->lease_expires_at);

        $runner->refresh();
        $this->assertSame($build->id, $runner->current_build_id);

        // A second claim finds nothing.
        $this->postJson('/api/runner/v1/builds/claim')->assertOk()->assertJsonPath('build', null);
    }

    public function test_start_marks_build_running_and_records_resolved_sha(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $build = Build::factory()->preparing($runner->id)->create(['commit_sha' => null]);
        $runner->forceFill(['current_build_id' => $build->id])->save();

        $sha = str_repeat('b', 40);

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/start', ['resolved_sha' => $sha])
            ->assertOk()
            ->assertJsonPath('build.status', 'running')
            ->assertJsonPath('build.commit_sha', $sha);

        $build->refresh();
        $this->assertSame(BuildStatus::Running, $build->status);
        $this->assertNotNull($build->started_at);

        // Idempotent retry by the same runner.
        $this->postJson('/api/runner/v1/builds/'.$build->id.'/start', ['resolved_sha' => $sha])->assertOk();
    }

    public function test_start_rejects_a_different_sha_than_requested(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $build = Build::factory()->preparing($runner->id)->create([
            'commit_sha' => str_repeat('a', 40),
        ]);

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/start', [
            'resolved_sha' => str_repeat('c', 40),
        ])->assertStatus(409);
    }

    public function test_another_runner_cannot_operate_a_build_it_does_not_own(): void
    {
        $owner = Runner::factory()->create();
        $intruder = Runner::factory()->withToken()->create();
        $this->actingAsRunner($intruder);

        $build = Build::factory()->preparing($owner->id)->create();

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/start', [])->assertStatus(409);
        $this->postJson('/api/runner/v1/builds/'.$build->id.'/logs', [
            'batch_id' => 'batch-1',
            'events' => [['line_no' => 0, 'stream' => 'stdout', 'message' => 'x']],
        ])->assertStatus(409);
        $this->postJson('/api/runner/v1/builds/'.$build->id.'/complete', [
            'status' => 'failed',
            'exit_code' => 1,
        ])->assertStatus(409);
        $this->getJson('/api/runner/v1/builds/'.$build->id.'/control')->assertStatus(409);
    }

    public function test_log_ingestion_is_idempotent_and_renews_the_lease(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $build = Build::factory()->running($runner->id)->create([
            'last_heartbeat_at' => now()->subMinutes(10),
            'lease_expires_at' => now()->subMinutes(5),
        ]);

        $payload = [
            'batch_id' => '018f0-abc',
            'events' => [
                ['line_no' => 0, 'stream' => 'stdout', 'message' => 'linha 1'],
                ['line_no' => 1, 'stream' => 'stderr', 'message' => 'linha 2'],
                ['line_no' => 2, 'stream' => 'stdout', 'message' => 'linha 3'],
            ],
        ];

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/logs', $payload)
            ->assertOk()
            ->assertJsonPath('accepted', 3)
            ->assertJsonPath('truncated', false)
            ->assertJsonPath('control.cancel_requested', false);

        // Retry of the same batch must not duplicate rows.
        $this->postJson('/api/runner/v1/builds/'.$build->id.'/logs', $payload)
            ->assertOk()
            ->assertJsonPath('accepted', 0)
            ->assertJsonPath('ignored', 3);

        $this->assertSame(3, $build->logs()->where('stream', '!=', 'system')->count());

        $build->refresh();
        $this->assertTrue($build->lease_expires_at->isFuture(), 'O envio de logs renova a lease.');
    }

    public function test_log_ingestion_accepts_empty_lines(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $build = Build::factory()->running($runner->id)->create();

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/logs', [
            'batch_id' => 'blank-lines',
            'events' => [
                ['line_no' => 0, 'stream' => 'stdout', 'message' => ''],
                ['line_no' => 1, 'stream' => 'stdout', 'message' => 'linha real'],
            ],
        ])->assertOk()->assertJsonPath('accepted', 2);

        $this->assertSame(2, $build->logs()->where('stream', '!=', 'system')->count());
    }

    public function test_log_batch_limits_are_enforced(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $build = Build::factory()->running($runner->id)->create();

        $events = [];
        for ($i = 0; $i < ((int) config('builds.log_max_batch_events') + 1); $i++) {
            $events[] = ['line_no' => $i, 'stream' => 'stdout', 'message' => 'x'];
        }

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/logs', [
            'batch_id' => 'too-big',
            'events' => $events,
        ])->assertStatus(422);
    }

    public function test_complete_success_requires_exit_code_and_records_duration(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $build = Build::factory()->running($runner->id)->create([
            'started_at' => now()->subSeconds(30),
        ]);

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/complete', [
            'status' => 'success',
            'exit_code' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('build.status', 'success');

        $build->refresh();
        $this->assertSame(0, $build->exit_code);
        $this->assertNotNull($build->finished_at);
        $this->assertGreaterThanOrEqual(29000, $build->duration_ms);

        $runner->refresh();
        $this->assertNull($runner->current_build_id);
    }

    public function test_complete_success_without_exit_code_is_downgraded_to_failure(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $build = Build::factory()->running($runner->id)->create();

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/complete', [
            'status' => 'success',
        ])->assertOk()->assertJsonPath('build.status', 'failed');

        $this->assertStringContainsString('código de saída', $build->fresh()->error_message);
    }

    public function test_complete_with_non_zero_exit_code_fails_the_build(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $build = Build::factory()->running($runner->id)->create();

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/complete', [
            'status' => 'failed',
            'exit_code' => 1,
            'error_message' => 'gradle falhou',
        ])->assertOk()->assertJsonPath('build.status', 'failed');

        $build->refresh();
        $this->assertSame(1, $build->exit_code);
        $this->assertSame('gradle falhou', $build->error_message);
    }

    public function test_complete_requires_expected_artifacts_for_success(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $project = Project::factory()->create();
        $profile = BuildProfile::factory()->withArtifactPatterns(['*.apk'])->create(['project_id' => $project->id]);
        $build = Build::factory()->running($runner->id)->create([
            'project_id' => $project->id,
            'build_profile_id' => $profile->id,
        ]);

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/complete', [
            'status' => 'success',
            'exit_code' => 0,
        ])->assertOk()->assertJsonPath('build.status', 'failed');

        $this->assertStringContainsString('Artefato esperado', $build->fresh()->error_message);
    }

    public function test_complete_is_idempotent_for_the_same_status(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $build = Build::factory()->running($runner->id)->create();

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/complete', [
            'status' => 'success',
            'exit_code' => 0,
        ])->assertOk();

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/complete', [
            'status' => 'success',
            'exit_code' => 0,
        ])->assertOk()->assertJsonPath('build.status', 'success');
    }

    public function test_complete_records_submit_result_for_auto_submit_profile(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $project = Project::factory()->create();
        $profile = BuildProfile::factory()->withAutoSubmit()->create(['project_id' => $project->id]);
        $build = Build::factory()->running($runner->id)->create([
            'project_id' => $project->id,
            'build_profile_id' => $profile->id,
        ]);

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/complete', [
            'status' => 'success',
            'exit_code' => 0,
            'submit_status' => 'success',
            'submit_message' => 'enviado',
            'submit_version_code' => '21',
        ])
            ->assertOk()
            ->assertJsonPath('build.status', 'success')
            ->assertJsonPath('build.submit_status', 'success')
            ->assertJsonPath('build.submit_version_code', '21');

        $build->refresh();
        $this->assertSame(SubmitStatus::Success, $build->submit_status);
        $this->assertSame('enviado', $build->submit_message);
        $this->assertSame('21', $build->submit_version_code);
        $this->assertNotNull($build->submitted_at);
    }

    public function test_complete_marks_submit_skipped_when_the_build_fails(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $project = Project::factory()->create();
        $profile = BuildProfile::factory()->withAutoSubmit()->create(['project_id' => $project->id]);
        $build = Build::factory()->running($runner->id)->create([
            'project_id' => $project->id,
            'build_profile_id' => $profile->id,
        ]);

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/complete', [
            'status' => 'failed',
            'exit_code' => 1,
            'error_message' => 'gradle falhou',
        ])->assertOk()->assertJsonPath('build.status', 'failed');

        $build->refresh();
        $this->assertSame(SubmitStatus::Skipped, $build->submit_status);
        $this->assertStringContainsString('não concluída', $build->submit_message);
        $this->assertNotNull($build->submitted_at);
    }

    public function test_control_endpoint_returns_cancel_flag(): void
    {
        $runner = Runner::factory()->withToken()->create();
        $this->actingAsRunner($runner);

        $build = Build::factory()->running($runner->id)->create([
            'cancel_requested_at' => now(),
        ]);

        $this->getJson('/api/runner/v1/builds/'.$build->id.'/control')
            ->assertOk()
            ->assertJsonPath('control.cancel_requested', true)
            ->assertJsonPath('control.status', 'running');
    }
}
