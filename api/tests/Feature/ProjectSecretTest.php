<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\BuildProfile;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\ProjectSecretFile;
use App\Models\Runner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectSecretTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_secrets_are_created_rotated_and_never_exposed(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create();

        $response = $this->postJson('/api/v1/projects/'.$project->id.'/secrets', [
            'key' => 'TENANT_BRANDS_TOKEN',
            'value' => 'super-secret-value',
        ])->assertCreated();

        $response->assertJsonPath('secret.key', 'TENANT_BRANDS_TOKEN');
        $this->assertStringNotContainsString('super-secret-value', $response->getContent());

        $secretId = $response->json('secret.id');

        // Encrypted at rest.
        $raw = (string) DB::table('project_secrets')->where('id', $secretId)->value('value');
        $this->assertStringNotContainsString('super-secret-value', $raw);
        $this->assertSame('super-secret-value', ProjectSecret::find($secretId)->value);

        // The list never returns values.
        $list = $this->getJson('/api/v1/projects/'.$project->id.'/secrets')->assertOk();
        $list->assertJsonPath('secrets.0.key', 'TENANT_BRANDS_TOKEN');
        $this->assertStringNotContainsString('super-secret-value', $list->getContent());

        // Rotation by value only.
        $rotated = $this->putJson('/api/v1/secrets/'.$secretId, ['value' => 'rotated-value'])
            ->assertOk();
        $this->assertStringNotContainsString('rotated-value', $rotated->getContent());
        $this->assertSame('rotated-value', ProjectSecret::find($secretId)->value);

        $this->deleteJson('/api/v1/secrets/'.$secretId)->assertOk();
        $this->assertDatabaseMissing('project_secrets', ['id' => $secretId]);
    }

    public function test_secret_keys_are_validated_and_unique(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create();

        $this->postJson('/api/v1/projects/'.$project->id.'/secrets', [
            'key' => 'BUILD_ID',
            'value' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['key']);

        $this->postJson('/api/v1/projects/'.$project->id.'/secrets', [
            'key' => '1INVALID',
            'value' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['key']);

        $this->postJson('/api/v1/projects/'.$project->id.'/secrets', [
            'key' => 'MY_TOKEN',
            'value' => 'x',
        ])->assertCreated();

        $this->postJson('/api/v1/projects/'.$project->id.'/secrets', [
            'key' => 'MY_TOKEN',
            'value' => 'y',
        ])->assertStatus(422)->assertJsonValidationErrors(['key']);
    }

    public function test_secret_files_are_uploaded_encrypted_and_removable(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create();
        $content = "keystore-binary-\x00\x01-content";

        $response = $this->post('/api/v1/projects/'.$project->id.'/secret-files', [
            'path' => 'credentials/android/keystore.jks',
            'file' => UploadedFile::fake()->createWithContent('keystore.jks', $content),
        ])->assertCreated();

        $response->assertJsonPath('secret_file.path', 'credentials/android/keystore.jks');
        $response->assertJsonPath('secret_file.size', strlen($content));
        $response->assertJsonPath('secret_file.sha256', hash('sha256', $content));
        $this->assertStringNotContainsString(base64_encode($content), $response->getContent());

        $fileId = $response->json('secret_file.id');

        // Encrypted at rest and readable back.
        $raw = (string) DB::table('project_secret_files')->where('id', $fileId)->value('content');
        $this->assertStringNotContainsString($content, $raw);
        $this->assertSame($content, ProjectSecretFile::find($fileId)->content);

        $list = $this->getJson('/api/v1/projects/'.$project->id.'/secrets')->assertOk();
        $list->assertJsonPath('secret_files.0.path', 'credentials/android/keystore.jks');
        $this->assertStringNotContainsString(base64_encode($content), $list->getContent());

        $this->deleteJson('/api/v1/secret-files/'.$fileId)->assertOk();
        $this->assertDatabaseMissing('project_secret_files', ['id' => $fileId]);
    }

    public function test_secret_file_paths_are_validated(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create();

        foreach (['../escape.txt', '/etc/passwd', '_runner/build.ps1', '.git/config', 'C:/x'] as $path) {
            $this->post('/api/v1/projects/'.$project->id.'/secret-files', [
                'path' => $path,
                'file' => UploadedFile::fake()->createWithContent('x.txt', 'x'),
            ])->assertStatus(422)->assertJsonValidationErrors(['path']);
        }

        $this->post('/api/v1/projects/'.$project->id.'/secret-files', [
            'path' => '.env',
            'file' => UploadedFile::fake()->createWithContent('.env', 'A=1'),
        ])->assertCreated();

        $this->post('/api/v1/projects/'.$project->id.'/secret-files', [
            'path' => '.env',
            'file' => UploadedFile::fake()->createWithContent('.env', 'A=2'),
        ])->assertStatus(422)->assertJsonValidationErrors(['path']);
    }

    public function test_runner_claim_payload_carries_project_secrets_and_files(): void
    {
        $runner = Runner::factory()->withToken()->create();
        Sanctum::actingAs($runner, ['runner']);

        $project = Project::factory()->create();
        $profile = BuildProfile::factory()->create(['project_id' => $project->id]);
        Build::factory()->create([
            'project_id' => $project->id,
            'build_profile_id' => $profile->id,
        ]);

        ProjectSecret::create([
            'project_id' => $project->id,
            'key' => 'TENANT_BRANDS_TOKEN',
            'value' => 'secret-token-value',
        ]);

        ProjectSecretFile::create([
            'project_id' => $project->id,
            'path' => '.env',
            'content' => 'EXPO_PUBLIC_API_URL=https://api.example.test',
            'size' => 42,
            'sha256' => str_repeat('a', 64),
        ]);

        $response = $this->postJson('/api/runner/v1/builds/claim')->assertOk();

        $response
            ->assertJsonPath('build.secrets.TENANT_BRANDS_TOKEN', 'secret-token-value')
            ->assertJsonPath('build.secret_files.0.path', '.env');

        $this->assertSame(
            'EXPO_PUBLIC_API_URL=https://api.example.test',
            base64_decode($response->json('build.secret_files.0.content_base64')),
        );
    }
}
