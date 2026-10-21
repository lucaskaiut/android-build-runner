<?php

namespace App\Services;

use App\Enums\ArtifactType;
use App\Models\Build;
use App\Models\BuildArtifact;
use App\Models\ProjectSecretFile;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Uploads a build AAB to Google Play and commits it to a track.
 *
 * This runs inside the API/scheduler (not the Windows runner), so the panel
 * owns the store credentials and the submission lifecycle.
 */
class GooglePlaySubmitService
{
    private const API_BASE = 'https://androidpublisher.googleapis.com/androidpublisher/v3';

    private const UPLOAD_BASE = 'https://androidpublisher.googleapis.com/upload/androidpublisher/v3';

    private const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    public function __construct(
        private readonly ArtifactService $artifacts,
    ) {}

    /**
     * @return array{version_code: string, track: string, release_status: string, warning: string|null}
     */
    public function submit(Build $build): array
    {
        $config = $build->submit_config;

        if (! is_array($config) || $config === []) {
            throw new RuntimeException('Este build não possui configuração de submit.');
        }

        $provider = (string) ($config['provider'] ?? 'google_play');
        if ($provider !== 'google_play') {
            throw new RuntimeException("Provedor de submit não suportado: {$provider}.");
        }

        $packageName = trim((string) ($config['package_name'] ?? ''));
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/', $packageName) !== 1) {
            throw new RuntimeException("Package name inválido no submit: '{$packageName}'.");
        }

        $track = trim((string) ($config['track'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $track) !== 1) {
            throw new RuntimeException("Track inválida no submit: '{$track}'.");
        }

        $releaseStatus = (string) ($config['release_status'] ?? 'completed');
        if (! in_array($releaseStatus, ['completed', 'draft'], true)) {
            throw new RuntimeException("Status de release inválido no submit: '{$releaseStatus}'.");
        }

        $serviceAccountFile = trim((string) ($config['service_account_file'] ?? ''));
        if ($serviceAccountFile === '') {
            throw new RuntimeException('O arquivo da service account do Google Play não foi configurado.');
        }

        $artifact = $this->findAab($build, $config);
        $artifactPath = $this->artifacts->absolutePath($artifact);
        if ($artifactPath === null) {
            throw new RuntimeException('O AAB do build não está mais disponível no storage da API.');
        }

        $secretFile = $build->project
            ->secretFiles()
            ->where('path', $serviceAccountFile)
            ->first();

        if (! $secretFile instanceof ProjectSecretFile) {
            throw new RuntimeException("Arquivo secreto da service account não encontrado: {$serviceAccountFile}.");
        }

        $serviceAccount = json_decode($secretFile->content, true);
        if (! is_array($serviceAccount)
            || ($serviceAccount['type'] ?? null) !== 'service_account'
            || empty($serviceAccount['client_email'])
            || empty($serviceAccount['private_key'])) {
            throw new RuntimeException("O arquivo {$serviceAccountFile} não é uma service account JSON válida.");
        }

        $accessToken = $this->accessToken($serviceAccount);
        $package = rawurlencode($packageName);
        $edit = $this->jsonRequest('POST', self::API_BASE."/applications/{$package}/edits", $accessToken, (object) []);

        $editId = $edit['id'] ?? null;
        if (! is_string($editId) || $editId === '') {
            throw new RuntimeException('A Google Play API não retornou o id da edição.');
        }

        $committed = false;

        try {
            $artifactUrl = self::UPLOAD_BASE."/applications/{$package}/edits/{$editId}/bundles?uploadType=media";
            $versionCode = $this->uploadBundle($artifactUrl, $artifactPath, $accessToken);

            $release = [
                'versionCodes' => [$versionCode],
                'status' => $releaseStatus,
            ];

            $releaseName = trim((string) ($config['release_name'] ?? ''));
            if ($releaseName !== '') {
                $release['name'] = $releaseName;
            }

            $trackUrl = self::API_BASE."/applications/{$package}/edits/{$editId}/tracks/".rawurlencode($track);
            $this->jsonRequest('PUT', $trackUrl, $accessToken, [
                'track' => $track,
                'releases' => [$release],
            ]);

            $commit = $this->jsonRequest(
                'POST',
                self::API_BASE."/applications/{$package}/edits/{$editId}:commit",
                $accessToken,
                (object) [],
            );

            $committed = true;

            return [
                'version_code' => $versionCode,
                'track' => $track,
                'release_status' => $releaseStatus,
                'warning' => ! empty($commit['changesNotSentForReview'])
                    ? 'changes_not_sent_for_review'
                    : null,
            ];
        } finally {
            if (! $committed) {
                try {
                    $this->jsonRequest(
                        'DELETE',
                        self::API_BASE."/applications/{$package}/edits/{$editId}",
                        $accessToken,
                    );
                } catch (\Throwable) {
                    // The edit expires automatically; never hide the original error.
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function findAab(Build $build, array $config): BuildArtifact
    {
        $artifacts = $build->artifacts()
            ->where('type', ArtifactType::Aab->value)
            ->orderByDesc('id')
            ->get();

        if ($artifacts->isEmpty()) {
            throw new RuntimeException('Nenhum AAB foi enviado pelo runner para este build.');
        }

        $pattern = trim((string) ($config['artifact_pattern'] ?? ''));

        if ($pattern !== '') {
            $matches = $artifacts->first(
                fn (BuildArtifact $artifact) => Str::is($pattern, (string) $artifact->relative_path)
                    || Str::is($pattern, (string) $artifact->original_name)
            );

            if ($matches !== null) {
                return $matches;
            }
        }

        return $artifacts->first();
    }

    /**
     * @param  array<string, mixed>  $serviceAccount
     */
    private function accessToken(array $serviceAccount): string
    {
        $now = time();
        $header = $this->base64Url(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));

        $payload = $this->base64Url(json_encode([
            'iss' => $serviceAccount['client_email'],
            'scope' => self::SCOPE,
            'aud' => $serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $signingInput = $header.'.'.$payload;
        $privateKey = openssl_pkey_get_private((string) $serviceAccount['private_key']);

        if ($privateKey === false) {
            throw new RuntimeException('Não foi possível carregar a chave privada da service account.');
        }

        $signature = '';
        if (! openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Não foi possível assinar o JWT da service account.');
        }

        $assertion = $signingInput.'.'.$this->base64Url($signature);

        $response = Http::asForm()
            ->timeout(60)
            ->post((string) ($serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token'), [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Falha ao autenticar na Google Play: '.$this->errorMessage($response));
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('A Google OAuth API não retornou access_token.');
        }

        return $token;
    }

    private function uploadBundle(string $url, string $path, string $accessToken): string
    {
        $size = filesize($path);
        $stream = fopen($path, 'rb');

        if ($size === false || $stream === false) {
            throw new RuntimeException('Não foi possível abrir o AAB para upload.');
        }

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Content-Type' => 'application/octet-stream',
                    'Content-Length' => (string) $size,
                ])
                ->withBody($stream, 'application/octet-stream')
                ->timeout((int) config('builds.submit_timeout_seconds', 900))
                ->post($url);
        } finally {
            fclose($stream);
        }

        $data = $this->decode($response);
        $versionCode = $data['versionCode'] ?? null;

        if (! is_int($versionCode) && ! is_string($versionCode)) {
            throw new RuntimeException('A Google Play API não retornou o versionCode após o upload do AAB.');
        }

        return (string) $versionCode;
    }

    /**
     * @param  array<string, mixed>|object  $payload
     * @return array<string, mixed>
     */
    private function jsonRequest(string $method, string $url, string $accessToken, array|object $payload = []): array
    {
        $request = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(60);

        if (strtoupper($method) === 'DELETE') {
            $response = $request->delete($url);
        } else {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $response = $request
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withBody($body, 'application/json')
                ->send($method, $url);
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');

        if (is_string($message) && $message !== '') {
            $details = $response->json('error.errors');

            if (is_array($details)) {
                $messages = array_filter(array_map(
                    fn ($item) => is_array($item) ? ($item['message'] ?? null) : null,
                    $details,
                ));

                if ($messages !== []) {
                    $message .= ' ('.implode('; ', $messages).')';
                }
            }

            return 'HTTP '.$response->status().': '.$message;
        }

        return 'HTTP '.$response->status().': '.Str::limit($response->body(), 800);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
