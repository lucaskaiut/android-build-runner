<?php

namespace Tests\Feature;

use App\Enums\BuildSource;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Build;
use App\Models\BuildProfile;
use App\Models\Project;
use App\Models\ProjectBranchTrigger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GithubWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'super-secret-webhook-value';

    private function pushPayload(string $repoUrl, string $branch, string $sha, array $extra = []): array
    {
        return array_merge([
            'ref' => 'refs/heads/'.$branch,
            'after' => $sha,
            'repository' => [
                'ssh_url' => $repoUrl,
                'clone_url' => 'https://github.com/acme/app.git',
            ],
            'head_commit' => ['id' => $sha],
        ], $extra);
    }

    private function sendWebhook(
        string $projectSlug,
        array $payload,
        ?string $signature = 'auto',
        string $deliveryId = 'delivery-1',
        string $event = 'push',
    ): TestResponse {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = $signature === 'auto'
            ? 'sha256='.hash_hmac('sha256', $body, self::SECRET)
            : $signature;

        return $this->call('POST', '/api/webhooks/github/'.$projectSlug, [], [], [], [
            'HTTP_X-Hub-Signature-256' => $signature,
            'HTTP_X-GitHub-Event' => $event,
            'HTTP_X-GitHub-Delivery' => $deliveryId,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /**
     * @return array{0: Project, 1: BuildProfile}
     */
    private function makeMonitoredProject(string $branch = 'main'): array
    {
        $project = Project::factory()->create([
            'repo_url' => 'git@github.com:acme/app.git',
            'webhook_enabled' => true,
            'webhook_secret' => self::SECRET,
        ]);

        $profile = BuildProfile::factory()->create(['project_id' => $project->id]);

        ProjectBranchTrigger::create([
            'project_id' => $project->id,
            'build_profile_id' => $profile->id,
            'branch_pattern' => $branch,
            'is_active' => true,
        ]);

        return [$project, $profile];
    }

    public function test_valid_push_creates_a_build_for_the_exact_commit(): void
    {
        [$project, $profile] = $this->makeMonitoredProject('main');
        $sha = str_repeat('a', 40);

        $response = $this->sendWebhook($project->slug, $this->pushPayload('git@github.com:acme/app.git', 'main', $sha))
            ->assertStatus(202)
            ->assertJsonPath('status', 'accepted');

        $build = Build::firstOrFail();
        $response->assertJsonPath('build_id', $build->id);
        $this->assertSame($project->id, $build->project_id);
        $this->assertSame($profile->id, $build->build_profile_id);
        $this->assertSame('main', $build->branch);
        $this->assertSame($sha, $build->commit_sha);
        $this->assertSame(BuildSource::Webhook, $build->source);

        $delivery = $project->webhookDeliveries()->firstOrFail();
        $this->assertSame(WebhookDeliveryStatus::Accepted, $delivery->status);
        $this->assertSame($build->id, $delivery->build_id);
        $this->assertSame($sha, $delivery->sha);
    }

    public function test_invalid_signature_is_rejected_without_poisoning_the_delivery(): void
    {
        [$project] = $this->makeMonitoredProject();
        $sha = str_repeat('b', 40);
        $payload = $this->pushPayload('git@github.com:acme/app.git', 'main', $sha);

        $this->sendWebhook($project->slug, $payload, signature: 'sha256=deadbeef')
            ->assertStatus(403);

        $this->assertSame(0, Build::count());

        $delivery = $project->webhookDeliveries()->firstOrFail();
        $this->assertSame(WebhookDeliveryStatus::Rejected, $delivery->status);
        $this->assertNull($delivery->delivery_id);

        // The same delivery id can still be processed with a valid signature.
        $this->sendWebhook($project->slug, $payload)->assertStatus(202);
        $this->assertSame(1, Build::count());
    }

    public function test_duplicate_deliveries_do_not_create_duplicate_builds(): void
    {
        [$project] = $this->makeMonitoredProject();
        $payload = $this->pushPayload('git@github.com:acme/app.git', 'main', str_repeat('c', 40));

        $this->sendWebhook($project->slug, $payload)->assertStatus(202);

        $this->sendWebhook($project->slug, $payload)
            ->assertStatus(200)
            ->assertJsonPath('status', 'duplicate')
            ->assertJsonPath('redelivery_count', 1);

        $this->assertSame(1, Build::count());
        $this->assertSame(1, $project->webhookDeliveries()->count());
    }

    public function test_webhook_for_unknown_project_is_not_registered_as_build(): void
    {
        $payload = $this->pushPayload('git@github.com:acme/app.git', 'main', str_repeat('d', 40));

        $this->sendWebhook('nao-existe', $payload)->assertStatus(404);

        $this->assertDatabaseHas('github_webhook_deliveries', [
            'status' => WebhookDeliveryStatus::Rejected->value,
            'project_id' => null,
        ]);
    }

    public function test_repository_mismatch_is_rejected(): void
    {
        [$project] = $this->makeMonitoredProject();

        $this->sendWebhook($project->slug, $this->pushPayload('git@github.com:outro/repo.git', 'main', str_repeat('e', 40)))
            ->assertStatus(403);

        $this->assertSame(0, Build::count());
    }

    public function test_unmonitored_branch_is_ignored(): void
    {
        [$project] = $this->makeMonitoredProject('main');

        $this->sendWebhook($project->slug, $this->pushPayload('git@github.com:acme/app.git', 'feature/x', str_repeat('f', 40)))
            ->assertStatus(202)
            ->assertJsonPath('status', 'ignored');

        $this->assertSame(0, Build::count());
    }

    public function test_wildcard_branch_patterns_match(): void
    {
        [$project] = $this->makeMonitoredProject('release/*');

        $this->sendWebhook($project->slug, $this->pushPayload('git@github.com:acme/app.git', 'release/2.0.0', str_repeat('1', 40)))
            ->assertStatus(202)
            ->assertJsonPath('status', 'accepted');

        $this->assertSame(1, Build::count());
    }

    public function test_automation_disabled_and_inactive_project_are_ignored(): void
    {
        [$project] = $this->makeMonitoredProject();
        $project->forceFill(['webhook_enabled' => false])->save();

        $this->sendWebhook($project->slug, $this->pushPayload('git@github.com:acme/app.git', 'main', str_repeat('2', 40)))
            ->assertStatus(202)
            ->assertJsonPath('status', 'ignored');

        $project->forceFill(['webhook_enabled' => true, 'is_active' => false])->save();

        $this->sendWebhook($project->slug, $this->pushPayload('git@github.com:acme/app.git', 'main', str_repeat('3', 40)), deliveryId: 'delivery-2')
            ->assertStatus(202)
            ->assertJsonPath('status', 'ignored');

        $this->assertSame(0, Build::count());
    }

    public function test_inactive_profile_associated_with_branch_is_ignored(): void
    {
        [$project, $profile] = $this->makeMonitoredProject();
        $profile->forceFill(['is_active' => false])->save();

        $this->sendWebhook($project->slug, $this->pushPayload('git@github.com:acme/app.git', 'main', str_repeat('4', 40)))
            ->assertStatus(202)
            ->assertJsonPath('status', 'ignored');

        $this->assertSame(0, Build::count());
    }

    public function test_webhook_payload_cannot_inject_configuration(): void
    {
        [$project, $profile] = $this->makeMonitoredProject();
        $sha = str_repeat('5', 40);

        $payload = $this->pushPayload('git@github.com:acme/app.git', 'main', $sha, [
            // Hostile fields that must be ignored.
            'build_script' => 'Remove-Item -Recurse C:\\',
            'working_directory' => '../../',
            'webhook_secret' => 'attacker',
            'build_profile_id' => 9999,
        ]);

        $this->sendWebhook($project->slug, $payload)->assertStatus(202);

        $build = Build::firstOrFail();
        $this->assertSame($profile->id, $build->build_profile_id);
        $this->assertSame('Write-Output "hello from the build script"', $profile->fresh()->build_script);
        $this->assertSame('git@github.com:acme/app.git', $project->fresh()->repo_url);
        $this->assertSame(self::SECRET, $project->fresh()->webhook_secret);
    }

    public function test_project_without_secret_returns_service_unavailable(): void
    {
        $project = Project::factory()->create(['webhook_enabled' => true, 'webhook_secret' => null]);

        $this->sendWebhook($project->slug, $this->pushPayload('git@github.com:acme/app.git', 'main', str_repeat('6', 40)))
            ->assertStatus(503);

        $this->assertDatabaseHas('github_webhook_deliveries', [
            'project_id' => $project->id,
            'status' => WebhookDeliveryStatus::Error->value,
        ]);
    }

    public function test_non_push_events_are_ignored(): void
    {
        [$project] = $this->makeMonitoredProject();

        $this->sendWebhook(
            $project->slug,
            $this->pushPayload('git@github.com:acme/app.git', 'main', str_repeat('7', 40)),
            event: 'pull_request',
        )->assertStatus(202)->assertJsonPath('status', 'ignored');

        $this->assertSame(0, Build::count());
    }
}
