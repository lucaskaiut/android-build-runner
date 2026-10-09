<?php

namespace Tests\Feature;

use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\BuildProfile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BuildCreationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_manual_build_is_created_queued_with_default_branch(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create(['default_branch' => 'develop']);
        $profile = BuildProfile::factory()->create(['project_id' => $project->id]);

        $response = $this->postJson('/api/v1/projects/'.$project->id.'/builds', [
            'build_profile_id' => $profile->id,
        ])->assertCreated();

        $response
            ->assertJsonPath('build.status', BuildStatus::Queued->value)
            ->assertJsonPath('build.source', BuildSource::Manual->value)
            ->assertJsonPath('build.branch', 'develop')
            ->assertJsonPath('build.commit_sha', null);

        $build = Build::find($response->json('build.id'));
        $this->assertNotNull($build->queued_at);

        // A system log entry records the creation.
        $this->assertDatabaseHas('build_logs', [
            'build_id' => $build->id,
            'stream' => 'system',
        ]);
    }

    public function test_build_accepts_explicit_commit_sha_and_validates_format(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create();
        $profile = BuildProfile::factory()->create(['project_id' => $project->id]);

        $sha = str_repeat('a', 40);

        $this->postJson('/api/v1/projects/'.$project->id.'/builds', [
            'build_profile_id' => $profile->id,
            'commit_sha' => strtoupper($sha),
        ])
            ->assertCreated()
            ->assertJsonPath('build.commit_sha', $sha)
            ->assertJsonPath('build.branch', null);

        $this->postJson('/api/v1/projects/'.$project->id.'/builds', [
            'build_profile_id' => $profile->id,
            'commit_sha' => 'abc123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['commit_sha']);
    }

    public function test_build_is_rejected_for_inactive_profile_or_project(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create();
        $inactiveProfile = BuildProfile::factory()->inactive()->create(['project_id' => $project->id]);

        $this->postJson('/api/v1/projects/'.$project->id.'/builds', [
            'build_profile_id' => $inactiveProfile->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['build_profile_id']);

        $inactiveProject = Project::factory()->inactive()->create();
        $profile = BuildProfile::factory()->create(['project_id' => $inactiveProject->id]);

        $this->postJson('/api/v1/projects/'.$inactiveProject->id.'/builds', [
            'build_profile_id' => $profile->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['project_id']);
    }

    public function test_branch_policy_is_enforced(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create(['allowed_branches' => ['main', 'release/*']]);
        $profile = BuildProfile::factory()->create(['project_id' => $project->id]);

        $this->postJson('/api/v1/projects/'.$project->id.'/builds', [
            'build_profile_id' => $profile->id,
            'branch' => 'release/1.2.3',
        ])->assertCreated();

        $this->postJson('/api/v1/projects/'.$project->id.'/builds', [
            'build_profile_id' => $profile->id,
            'branch' => 'hotfix/urgent',
        ])->assertStatus(422)->assertJsonValidationErrors(['branch']);
    }

    public function test_profile_parameters_are_validated_and_persisted(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create();
        $profile = BuildProfile::factory()->create([
            'project_id' => $project->id,
            'parameters' => [
                ['key' => 'variant', 'label' => 'Variante', 'required' => true, 'options' => ['preview', 'production']],
                ['key' => 'channel', 'label' => 'Canal', 'required' => false, 'default' => 'internal'],
            ],
        ]);

        // Missing required parameter.
        $this->postJson('/api/v1/projects/'.$project->id.'/builds', [
            'build_profile_id' => $profile->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['parameters.variant']);

        // Value outside allowed options.
        $this->postJson('/api/v1/projects/'.$project->id.'/builds', [
            'build_profile_id' => $profile->id,
            'parameters' => ['variant' => 'staging'],
        ])->assertStatus(422)->assertJsonValidationErrors(['parameters.variant']);

        // Unknown parameter.
        $this->postJson('/api/v1/projects/'.$project->id.'/builds', [
            'build_profile_id' => $profile->id,
            'parameters' => ['variant' => 'preview', 'evil' => 'cmd.exe'],
        ])->assertStatus(422)->assertJsonValidationErrors(['parameters.evil']);

        // Valid: defaults are applied.
        $response = $this->postJson('/api/v1/projects/'.$project->id.'/builds', [
            'build_profile_id' => $profile->id,
            'parameters' => ['variant' => 'preview'],
        ])->assertCreated();

        $this->assertSame(
            ['variant' => 'preview', 'channel' => 'internal'],
            $response->json('build.parameters'),
        );
    }

    public function test_build_listing_supports_filters(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create();
        $profile = BuildProfile::factory()->create(['project_id' => $project->id]);

        Build::factory()->create(['project_id' => $project->id, 'build_profile_id' => $profile->id, 'branch' => 'main']);
        Build::factory()->create(['project_id' => $project->id, 'build_profile_id' => $profile->id, 'status' => BuildStatus::Failed, 'branch' => 'other']);

        $this->getJson('/api/v1/builds?project_id='.$project->id.'&status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'failed');

        $this->getJson('/api/v1/builds?branch=main')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
