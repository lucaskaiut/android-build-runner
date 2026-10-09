<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_projects_can_be_listed_and_viewed(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create(['name' => 'App Mobile']);

        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'App Mobile')
            ->assertJsonPath('data.0.webhook_secret_configured', false)
            ->assertJsonMissingPath('data.0.webhook_secret');

        $this->getJson('/api/v1/projects/'.$project->id)
            ->assertOk()
            ->assertJsonPath('project.slug', $project->slug)
            ->assertJsonStructure(['project', 'profiles', 'branch_triggers']);
    }

    public function test_project_creation_validates_repository_and_paths(): void
    {
        $this->actingAsAdmin();

        $payload = [
            'name' => 'Novo Projeto',
            'slug' => 'novo-projeto',
            'repo_url' => 'not-a-normal-url',
            'default_branch' => 'main',
            'working_directory' => '../etc',
        ];

        $this->postJson('/api/v1/projects', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['repo_url', 'working_directory']);
    }

    public function test_project_can_be_created_updated_and_deactivated(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Novo Projeto',
            'slug' => 'novo-projeto',
            'repo_url' => 'git@github.com:acme/app.git',
            'default_branch' => 'main',
            'working_directory' => 'apps/mobile',
            'allowed_branches' => ['main', 'release/*'],
        ])->assertCreated();

        $projectId = $response->json('project.id');

        $this->putJson('/api/v1/projects/'.$projectId, [
            'name' => 'Projeto Renomeado',
            'working_directory' => 'apps/app',
        ])->assertOk()->assertJsonPath('project.name', 'Projeto Renomeado');

        $this->postJson('/api/v1/projects/'.$projectId.'/deactivate')
            ->assertOk()
            ->assertJsonPath('project.is_active', false);

        $this->postJson('/api/v1/projects/'.$projectId.'/activate')
            ->assertOk()
            ->assertJsonPath('project.is_active', true);
    }

    public function test_project_urls_are_normalized_for_comparison(): void
    {
        $this->assertSame(
            'github.com/acme/app',
            Project::normalizeRepositoryUrl('git@github.com:acme/app.git'),
        );
        $this->assertSame(
            'github.com/acme/app',
            Project::normalizeRepositoryUrl('https://github.com/acme/app.git'),
        );
        $this->assertSame(
            'github.com/acme/app',
            Project::normalizeRepositoryUrl('ssh://git@github.com/acme/app.git'),
        );
        $this->assertNull(Project::normalizeRepositoryUrl('ftp://example.com/repo'));
        $this->assertNull(Project::normalizeRepositoryUrl('../../etc/passwd'));
    }

    public function test_project_with_builds_cannot_be_deleted_but_deactivation_keeps_history(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create();
        $build = Build::factory()->create(['project_id' => $project->id]);

        $this->deleteJson('/api/v1/projects/'.$project->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project']);

        $this->postJson('/api/v1/projects/'.$project->id.'/deactivate')->assertOk();

        $this->assertDatabaseHas('builds', ['id' => $build->id]);
        $this->getJson('/api/v1/builds/'.$build->id)->assertOk();
    }

    public function test_project_without_builds_can_be_deleted(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create();

        $this->deleteJson('/api/v1/projects/'.$project->id)->assertOk();
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_webhook_secret_is_rotated_and_never_exposed_again(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create();

        $response = $this->postJson('/api/v1/projects/'.$project->id.'/webhook-secret')
            ->assertOk()
            ->assertJsonPath('project.webhook_secret_configured', true);

        $secret = $response->json('webhook_secret');
        $this->assertIsString($secret);
        $this->assertGreaterThanOrEqual(32, strlen($secret));

        // Never returned by the regular endpoints.
        $this->getJson('/api/v1/projects/'.$project->id)
            ->assertOk()
            ->assertJsonMissing(['webhook_secret' => $secret]);
    }
}
