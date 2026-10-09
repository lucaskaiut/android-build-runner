<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\Project;
use App\Models\Runner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_reports_real_counts(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Build::factory()->create(['status' => BuildStatus::Queued]);
        Build::factory()->create(['status' => BuildStatus::Queued]);
        Build::factory()->create(['status' => BuildStatus::Success, 'finished_at' => now()]);
        Build::factory()->create(['status' => BuildStatus::Failed, 'finished_at' => now()]);

        Runner::factory()->online()->create();
        Runner::factory()->create(['last_heartbeat_at' => now()->subHours(5)]);

        $response = $this->getJson('/api/v1/dashboard')->assertOk();

        $response
            ->assertJsonPath('stats.by_status.queued', 2)
            ->assertJsonPath('stats.by_status.success', 1)
            ->assertJsonPath('stats.by_status.failed', 1)
            ->assertJsonPath('stats.by_status.running', 0)
            ->assertJsonPath('stats.runners_online', 1)
            ->assertJsonPath('stats.runners_total', 2);

        $this->assertCount(4, $response->json('recent_builds'));
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_project_payload_never_exposes_the_webhook_secret(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $project = Project::factory()->create([
            'webhook_enabled' => true,
            'webhook_secret' => 'my-very-secret',
        ]);

        $this->getJson('/api/v1/projects/'.$project->id)
            ->assertOk()
            ->assertDontSee('my-very-secret', false);

        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertDontSee('my-very-secret', false);
    }
}
