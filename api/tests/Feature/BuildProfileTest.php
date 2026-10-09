<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\BuildProfile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BuildProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_profiles(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $project = Project::factory()->create();

        $response = $this->postJson('/api/v1/projects/'.$project->id.'/profiles', [
            'name' => 'Production',
            'slug' => 'production',
            'build_script' => 'npm ci; npx expo prebuild --platform android; cd android; ./gradlew assembleRelease',
            'working_directory' => 'apps/mobile',
            'timeout_seconds' => 7200,
            'artifact_patterns' => ['android/app/build/outputs/apk/release/*.apk'],
            'env' => ['APP_VARIANT' => 'production'],
            'parameters' => [
                ['key' => 'variant', 'label' => 'Variante', 'required' => true, 'options' => ['preview', 'production']],
            ],
        ])->assertCreated()
            ->assertJsonPath('profile.slug', 'production')
            ->assertJsonPath('profile.timeout_seconds', 7200);

        $profileId = $response->json('profile.id');

        $this->getJson('/api/v1/projects/'.$project->id.'/profiles')
            ->assertOk()
            ->assertJsonCount(1, 'profiles');

        $this->putJson('/api/v1/profiles/'.$profileId, [
            'name' => 'Production v2',
            'build_script' => 'Write-Output "updated"',
        ])->assertOk()->assertJsonPath('profile.name', 'Production v2');

        $this->postJson('/api/v1/profiles/'.$profileId.'/deactivate')
            ->assertOk()
            ->assertJsonPath('profile.is_active', false);

        $this->postJson('/api/v1/profiles/'.$profileId.'/activate')
            ->assertOk()
            ->assertJsonPath('profile.is_active', true);
    }

    public function test_profile_validation_rejects_unsafe_values(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $project = Project::factory()->create();

        $this->postJson('/api/v1/projects/'.$project->id.'/profiles', [
            'name' => 'Bad',
            'slug' => 'bad',
            'build_script' => 'Write-Output "x"',
            'working_directory' => '../../windows/system32',
            'timeout_seconds' => 5,
            'artifact_patterns' => ['../secrets/*'],
            'env' => ['lowercase' => 'nope'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['working_directory', 'timeout_seconds', 'artifact_patterns.0', 'env']);
    }

    public function test_profile_slug_is_unique_per_project(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $project = Project::factory()->create();
        BuildProfile::factory()->create(['project_id' => $project->id, 'slug' => 'preview']);

        $this->postJson('/api/v1/projects/'.$project->id.'/profiles', [
            'name' => 'Preview 2',
            'slug' => 'preview',
            'build_script' => 'Write-Output "x"',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_non_admin_users_cannot_edit_profiles(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

        $project = Project::factory()->create();

        $this->postJson('/api/v1/projects/'.$project->id.'/profiles', [
            'name' => 'Ops',
            'slug' => 'ops',
            'build_script' => 'Write-Output "x"',
        ])->assertForbidden();

        $profile = BuildProfile::factory()->create(['project_id' => $project->id]);

        $this->putJson('/api/v1/profiles/'.$profile->id, ['name' => 'Renamed'])->assertForbidden();
    }

    public function test_profile_with_builds_cannot_be_deleted(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $build = Build::factory()->create();
        $profile = $build->profile;

        $this->deleteJson('/api/v1/profiles/'.$profile->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile']);

        $this->assertDatabaseHas('build_profiles', ['id' => $profile->id]);
    }
}
