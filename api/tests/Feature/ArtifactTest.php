<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\BuildArtifact;
use App\Models\BuildProfile;
use App\Models\Project;
use App\Models\Runner;
use App\Models\User;
use App\Services\ArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArtifactTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $artifactDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->artifactDirectories as $directory) {
            if (is_dir($directory)) {
                foreach (glob($directory.'/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($directory);
            }
        }

        parent::tearDown();
    }

    /**
     * @return array{0: Runner, 1: Build}
     */
    private function makeRunningBuild(): array
    {
        $runner = Runner::factory()->create();

        $build = Build::factory()->create([
            'status' => BuildStatus::Running,
            'runner_id' => $runner->id,
            'claimed_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
            'last_heartbeat_at' => now(),
            'lease_expires_at' => now()->addMinutes(3),
            'commit_sha' => str_repeat('a', 40),
        ]);

        return [$runner, $build];
    }

    public function test_runner_uploads_artifacts_with_hash_validation(): void
    {
        [$runner, $build] = $this->makeRunningBuild();
        $this->artifactDirectories[] = storage_path('app/artifacts/'.$build->id);
        Sanctum::actingAs($runner, ['runner']);

        $content = 'fake-apk-binary-content';
        $file = UploadedFile::fake()->createWithContent('app-release.apk', $content);

        $response = $this->post('/api/runner/v1/builds/'.$build->id.'/artifacts', [
            'file' => $file,
            'sha256' => hash('sha256', $content),
            'relative_path' => 'out/app-release.apk',
        ])->assertCreated();

        $response
            ->assertJsonPath('artifact.original_name', 'app-release.apk')
            ->assertJsonPath('artifact.relative_path', 'out/app-release.apk')
            ->assertJsonPath('artifact.type', 'apk')
            ->assertJsonPath('artifact.size_bytes', strlen($content));

        $artifact = BuildArtifact::firstOrFail();
        $this->assertFileExists(storage_path('app/'.$artifact->stored_name));
        $this->assertStringStartsWith('artifacts/'.$build->id.'/', $artifact->stored_name);

        // Idempotent retry: same content yields the same artifact.
        $retry = UploadedFile::fake()->createWithContent('app-release.apk', $content);
        $this->post('/api/runner/v1/builds/'.$build->id.'/artifacts', [
            'file' => $retry,
            'sha256' => hash('sha256', $content),
            'relative_path' => 'out/app-release.apk',
        ])->assertOk()->assertJsonPath('artifact.id', $artifact->id);

        $this->assertSame(1, BuildArtifact::count());
    }

    public function test_artifact_upload_rejects_wrong_hash_and_missing_file(): void
    {
        [$runner, $build] = $this->makeRunningBuild();
        Sanctum::actingAs($runner, ['runner']);

        $this->post('/api/runner/v1/builds/'.$build->id.'/artifacts', [
            'file' => UploadedFile::fake()->createWithContent('app.apk', 'data'),
            'sha256' => str_repeat('0', 64),
        ])->assertStatus(422);

        $this->post('/api/runner/v1/builds/'.$build->id.'/artifacts', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_a_runner_cannot_upload_artifacts_for_a_build_it_does_not_own(): void
    {
        [, $build] = $this->makeRunningBuild();

        $intruder = Runner::factory()->create();
        Sanctum::actingAs($intruder, ['runner']);

        $this->post('/api/runner/v1/builds/'.$build->id.'/artifacts', [
            'file' => UploadedFile::fake()->createWithContent('app.apk', 'data'),
        ])->assertStatus(409);
    }

    public function test_files_are_never_stored_above_the_configured_limit(): void
    {
        config(['builds.artifact_max_megabytes' => 0]);

        [$runner, $build] = $this->makeRunningBuild();
        Sanctum::actingAs($runner, ['runner']);

        $this->post('/api/runner/v1/builds/'.$build->id.'/artifacts', [
            'file' => UploadedFile::fake()->createWithContent('app.apk', 'x'),
        ])->assertStatus(422);
    }

    public function test_artifact_download_requires_authentication(): void
    {
        $build = Build::factory()->create();
        $artifact = BuildArtifact::factory()->create(['build_id' => $build->id]);

        $this->getJson('/api/v1/artifacts/'.$artifact->id.'/download')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/artifacts/'.$artifact->id.'/download')->assertNotFound();
    }

    public function test_artifact_download_streams_the_stored_file(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $build = Build::factory()->create();
        $directory = storage_path('app/artifacts/'.$build->id);
        $this->artifactDirectories[] = $directory;

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.'/app-release.apk';
        file_put_contents($path, 'apk-bytes');

        $artifact = BuildArtifact::factory()->create([
            'build_id' => $build->id,
            'stored_name' => 'artifacts/'.$build->id.'/app-release.apk',
            'original_name' => 'app-release.apk',
            'size_bytes' => strlen('apk-bytes'),
            'sha256' => hash('sha256', 'apk-bytes'),
        ]);

        $response = $this->get('/api/v1/artifacts/'.$artifact->id.'/download');
        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        $this->assertSame('apk-bytes', $response->streamedContent());
    }

    public function test_tampered_storage_paths_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $build = Build::factory()->create();
        $artifact = BuildArtifact::factory()->create([
            'build_id' => $build->id,
            'stored_name' => '../../.env',
        ]);

        $this->getJson('/api/v1/artifacts/'.$artifact->id.'/download')->assertNotFound();
    }

    public function test_expected_artifact_patterns_match_relative_paths(): void
    {
        $runner = Runner::factory()->create();
        $project = Project::factory()->create();
        $profile = BuildProfile::factory()
            ->withArtifactPatterns(['android/app/build/outputs/apk/release/*.apk'])
            ->create(['project_id' => $project->id]);
        $build = Build::factory()->running($runner->id)->create([
            'project_id' => $project->id,
            'build_profile_id' => $profile->id,
        ]);

        $service = app(ArtifactService::class);

        $this->assertFalse($service->hasExpectedArtifacts($build));

        BuildArtifact::factory()->create([
            'build_id' => $build->id,
            'original_name' => 'app-release.apk',
            'relative_path' => 'android/app/build/outputs/apk/release/app-release.apk',
        ]);

        $this->assertTrue($service->hasExpectedArtifacts($build->fresh()));
    }

    public function test_relative_path_is_sanitized_and_never_used_for_storage(): void
    {
        [$runner, $build] = $this->makeRunningBuild();
        $this->artifactDirectories[] = storage_path('app/artifacts/'.$build->id);
        Sanctum::actingAs($runner, ['runner']);

        $response = $this->post('/api/runner/v1/builds/'.$build->id.'/artifacts', [
            'file' => UploadedFile::fake()->createWithContent('app.apk', 'conteudo-1'),
            'relative_path' => '../../outside/app.apk',
        ])->assertCreated();

        $response->assertJsonPath('artifact.relative_path', null);

        $artifact = BuildArtifact::firstOrFail();
        $this->assertStringStartsWith('artifacts/'.$build->id.'/', $artifact->stored_name);

        // Same bytes on different relative paths are kept as distinct artifacts.
        $this->post('/api/runner/v1/builds/'.$build->id.'/artifacts', [
            'file' => UploadedFile::fake()->createWithContent('other.apk', 'conteudo-1'),
            'relative_path' => 'libs/other.apk',
        ])->assertCreated();

        $this->assertSame(2, BuildArtifact::count());
    }
}
