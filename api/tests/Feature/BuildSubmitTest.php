<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Enums\SubmitStatus;
use App\Models\Build;
use App\Models\Runner;
use App\Services\GooglePlaySubmitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class BuildSubmitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function submitConfig(): array
    {
        return [
            'provider' => 'google_play',
            'package_name' => 'com.example.app',
            'track' => 'alpha',
            'service_account_file' => 'credentials.json',
            'artifact_pattern' => 'android/app/build/outputs/bundle/release/*.aab',
            'release_status' => 'completed',
            'release_name' => null,
        ];
    }

    private function successfulBuild(array $overrides = []): Build
    {
        return Build::factory()->create(array_merge([
            'status' => BuildStatus::Success,
            'submit_status' => SubmitStatus::Pending,
            'submit_config' => $this->submitConfig(),
            'finished_at' => now(),
        ], $overrides));
    }

    public function test_command_submits_a_successful_build_and_records_the_result(): void
    {
        $this->mock(GooglePlaySubmitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submit')
                ->once()
                ->andReturn([
                    'version_code' => '21',
                    'track' => 'alpha',
                    'release_status' => 'completed',
                    'warning' => null,
                ]);
        });

        $build = $this->successfulBuild();

        $this->artisan('builds:submit')->assertSuccessful();

        $build->refresh();
        $this->assertSame(SubmitStatus::Success, $build->submit_status);
        $this->assertSame('21', $build->submit_version_code);
        $this->assertSame(1, $build->submit_attempts);
        $this->assertNotNull($build->submit_started_at);
        $this->assertNotNull($build->submitted_at);
        $this->assertNull($build->submit_lease_expires_at);
        $this->assertStringContainsString('versionCode 21', (string) $build->submit_message);
        $this->assertDatabaseHas('build_logs', [
            'build_id' => $build->id,
            'stream' => 'system',
        ]);
    }

    public function test_command_marks_a_failed_submit_as_failed(): void
    {
        $this->mock(GooglePlaySubmitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submit')
                ->once()
                ->andThrow(new RuntimeException('credencial inválida'));
        });

        $build = $this->successfulBuild();

        $this->artisan('builds:submit')->assertSuccessful();

        $build->refresh();
        $this->assertSame(SubmitStatus::Failed, $build->submit_status);
        $this->assertStringContainsString('credencial inválida', (string) $build->submit_message);
        $this->assertNull($build->submit_version_code);
        $this->assertNull($build->submit_lease_expires_at);
    }

    public function test_command_recovers_an_expired_processing_lease(): void
    {
        $this->mock(GooglePlaySubmitService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('submit');
        });

        $build = $this->successfulBuild([
            'submit_status' => SubmitStatus::Processing,
            'submit_started_at' => now()->subMinutes(30),
            'submit_lease_expires_at' => now()->subMinute(),
        ]);

        $this->artisan('builds:submit')->assertSuccessful();

        $build->refresh();
        $this->assertSame(SubmitStatus::Failed, $build->submit_status);
        $this->assertStringContainsString('interrompido', (string) $build->submit_message);
        $this->assertNull($build->submit_lease_expires_at);
    }

    public function test_successful_completion_keeps_submit_pending_for_the_scheduler(): void
    {
        $runner = Runner::factory()->withToken()->create();
        Sanctum::actingAs($runner, ['runner']);

        $build = Build::factory()->running($runner->id)->create([
            'submit_status' => SubmitStatus::Pending,
            'submit_config' => $this->submitConfig(),
        ]);

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/complete', [
            'status' => 'success',
            'exit_code' => 0,
        ])->assertOk()->assertJsonPath('build.status', 'success');

        $build->refresh();
        $this->assertSame(SubmitStatus::Pending, $build->submit_status);
        $this->assertNull($build->submit_started_at);
        $this->assertNull($build->submitted_at);
    }

    public function test_failed_completion_marks_submit_as_skipped(): void
    {
        $runner = Runner::factory()->withToken()->create();
        Sanctum::actingAs($runner, ['runner']);

        $build = Build::factory()->running($runner->id)->create([
            'submit_status' => SubmitStatus::Pending,
            'submit_config' => $this->submitConfig(),
        ]);

        $this->postJson('/api/runner/v1/builds/'.$build->id.'/complete', [
            'status' => 'failed',
            'exit_code' => 1,
            'error_message' => 'gradle falhou',
        ])->assertOk()->assertJsonPath('build.status', 'failed');

        $build->refresh();
        $this->assertSame(SubmitStatus::Skipped, $build->submit_status);
        $this->assertNotNull($build->submitted_at);
        $this->assertStringContainsString('não executado', (string) $build->submit_message);
    }

    public function test_command_ignores_builds_without_a_submit_snapshot(): void
    {
        $this->mock(GooglePlaySubmitService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('submit');
        });

        $build = $this->successfulBuild([
            'submit_status' => SubmitStatus::Pending,
            'submit_config' => null,
        ]);

        $this->artisan('builds:submit')->assertSuccessful();

        $build->refresh();
        $this->assertSame(SubmitStatus::Pending, $build->submit_status);
    }
}
