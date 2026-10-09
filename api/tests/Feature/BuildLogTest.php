<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\Runner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BuildLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_are_returned_in_order_and_support_after_id(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $runner = Runner::factory()->create();
        $build = Build::factory()->running($runner->id)->create();

        $payload = [
            'batch_id' => 'batch-log-test',
            'events' => [
                ['line_no' => 0, 'stream' => 'stdout', 'message' => 'primeira'],
                ['line_no' => 1, 'stream' => 'stderr', 'message' => 'segunda'],
            ],
        ];

        Sanctum::actingAs($runner, ['runner']);
        $this->postJson('/api/runner/v1/builds/'.$build->id.'/logs', $payload)->assertOk();

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/v1/builds/'.$build->id.'/logs')->assertOk();
        $messages = $response->json('logs');

        $this->assertSame('primeira', $messages[0]['message']);
        $this->assertSame('segunda', $messages[1]['message']);

        $lastId = $response->json('last_id');
        $this->assertGreaterThan(0, $lastId);

        $this->getJson('/api/v1/builds/'.$build->id.'/logs?after_id='.$lastId)
            ->assertOk()
            ->assertJsonCount(0, 'logs');
    }

    public function test_sse_stream_replays_logs_and_ends_for_terminal_builds(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $runner = Runner::factory()->create();
        $build = Build::factory()->running($runner->id)->create();

        Sanctum::actingAs($runner, ['runner']);
        $this->postJson('/api/runner/v1/builds/'.$build->id.'/logs', [
            'batch_id' => 'batch-sse',
            'events' => [
                ['line_no' => 0, 'stream' => 'stdout', 'message' => 'linha sse'],
            ],
        ])->assertOk();

        $build->forceFill([
            'status' => BuildStatus::Success,
            'exit_code' => 0,
            'finished_at' => now(),
        ])->save();

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->get('/api/v1/builds/'.$build->id.'/logs/stream');
        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', (string) $response->headers->get('content-type'));

        $content = $response->streamedContent();

        $this->assertStringContainsString('event: log', $content);
        $this->assertStringContainsString('linha sse', $content);
        $this->assertStringContainsString('event: status', $content);
        $this->assertStringContainsString('event: end', $content);
    }

    public function test_stream_requires_authentication(): void
    {
        $build = Build::factory()->create();

        $this->getJson('/api/v1/builds/'.$build->id.'/logs/stream')->assertUnauthorized();
        $this->getJson('/api/v1/builds/'.$build->id.'/logs')->assertUnauthorized();
    }
}
