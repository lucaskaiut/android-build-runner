<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Enums\SubmitStatus;
use App\Models\Build;
use App\Models\BuildArtifact;
use App\Models\Project;
use App\Models\ProjectSecretFile;
use App\Services\GooglePlaySubmitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GooglePlaySubmitServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_uploads_and_commits_the_aab(): void
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($privateKey, 'Não foi possível gerar a chave RSA de teste.');
        $this->assertTrue(openssl_pkey_export($privateKey, $pem));

        $serviceAccount = [
            'type' => 'service_account',
            'client_email' => 'play-submit@example.test',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ];

        $project = Project::factory()->create();
        $build = Build::factory()->create([
            'project_id' => $project->id,
            'status' => BuildStatus::Success,
            'submit_status' => SubmitStatus::Pending,
            'submit_config' => [
                'provider' => 'google_play',
                'package_name' => 'com.example.app',
                'track' => 'alpha',
                'service_account_file' => 'credentials.json',
                'artifact_pattern' => 'android/app/build/outputs/bundle/release/*.aab',
                'release_status' => 'completed',
                'release_name' => '1.0.0',
            ],
        ]);

        ProjectSecretFile::create([
            'project_id' => $project->id,
            'path' => 'credentials.json',
            'content' => json_encode($serviceAccount, JSON_THROW_ON_ERROR),
            'size' => strlen(json_encode($serviceAccount, JSON_THROW_ON_ERROR)),
            'sha256' => hash('sha256', json_encode($serviceAccount, JSON_THROW_ON_ERROR)),
        ]);

        $storedName = 'artifacts/'.$build->id.'/test.aab';
        $absolutePath = storage_path('app/'.$storedName);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'fake-aab-content');

        BuildArtifact::create([
            'build_id' => $build->id,
            'original_name' => 'app-release.aab',
            'relative_path' => 'android/app/build/outputs/bundle/release/app-release.aab',
            'stored_name' => $storedName,
            'type' => 'aab',
            'size_bytes' => filesize($absolutePath),
            'sha256' => hash('sha256', 'fake-aab-content'),
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = $request->method();

            if (str_starts_with($url, 'https://oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'test-token'], 200);
            }

            if ($method === 'POST' && str_ends_with($url, '/edits')) {
                return Http::response(['id' => 'edit-123'], 200);
            }

            if ($method === 'POST' && str_contains($url, '/bundles?uploadType=media')) {
                return Http::response(['versionCode' => 21], 200);
            }

            if ($method === 'PUT' && str_contains($url, '/edits/edit-123/tracks/alpha')) {
                return Http::response([], 200);
            }

            if ($method === 'POST' && str_contains($url, '/edits/edit-123:commit')) {
                return Http::response(['id' => 'edit-123'], 200);
            }

            if ($method === 'DELETE' && str_contains($url, '/edits/edit-123')) {
                return Http::response(null, 204);
            }

            throw new RuntimeException("Requisição inesperada: {$method} {$url}");
        });

        try {
            $result = app(GooglePlaySubmitService::class)->submit($build);

            $this->assertSame('21', $result['version_code']);
            $this->assertSame('alpha', $result['track']);
            $this->assertSame('completed', $result['release_status']);

            Http::assertSent(fn (Request $request) => $request->method() === 'POST'
                && str_contains($request->url(), '/upload/androidpublisher/v3/applications/com.example.app/edits/edit-123/bundles?uploadType=media'));

            Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
                && str_contains($request->url(), '/edits/edit-123/tracks/alpha'));

            Http::assertSent(fn (Request $request) => $request->method() === 'POST'
                && str_contains($request->url(), '/edits/edit-123:commit'));
        } finally {
            File::deleteDirectory(storage_path('app/artifacts/'.$build->id));
        }
    }

    public function test_service_fails_when_no_aab_was_uploaded(): void
    {
        $build = Build::factory()->create([
            'status' => BuildStatus::Success,
            'submit_status' => SubmitStatus::Pending,
            'submit_config' => [
                'provider' => 'google_play',
                'package_name' => 'com.example.app',
                'track' => 'alpha',
                'service_account_file' => 'credentials.json',
                'artifact_pattern' => '*.aab',
                'release_status' => 'completed',
                'release_name' => null,
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nenhum AAB');

        app(GooglePlaySubmitService::class)->submit($build);
    }
}
