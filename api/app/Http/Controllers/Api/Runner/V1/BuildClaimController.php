<?php

namespace App\Http\Controllers\Api\Runner\V1;

use App\Http\Controllers\Controller;
use App\Models\Build;
use App\Models\ProjectSecret;
use App\Models\ProjectSecretFile;
use App\Models\Runner;
use App\Services\BuildClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuildClaimController extends Controller
{
    public function __construct(
        private readonly BuildClaimService $claims,
    ) {}

    public function store(Request $request): JsonResponse
    {
        /** @var Runner $runner */
        $runner = $request->user();

        $build = $this->claims->claim($runner);

        if ($build === null) {
            return response()->json([
                'build' => null,
                'server_time' => now()->toIso8601String(),
            ]);
        }

        return response()->json([
            'build' => $this->payload($build),
        ]);
    }

    /**
     * Returns the execution payload of a build already assigned to this
     * runner. Used to recover from a lost claim response or a runner restart.
     */
    public function show(Request $request, Build $build): JsonResponse
    {
        /** @var Runner $runner */
        $runner = $request->user();

        if ((int) $build->runner_id !== (int) $runner->id) {
            throw \App\Exceptions\BuildConflictException::notOwnedByRunner();
        }

        if ($build->status->isTerminal()) {
            throw \App\Exceptions\BuildConflictException::invalidState(
                $build->status,
                'A build já foi finalizada.',
            );
        }

        return response()->json([
            'build' => $this->payload($build),
        ]);
    }

    /**
     * The runner receives only what it needs to execute the build. Platform
     * credentials (webhook secret, runner tokens) are never part of this
     * payload; build secrets belong to the project and are delivered only to
     * the runner that owns the claimed build.
     *
     * @return array<string, mixed>
     */
    private function payload(Build $build): array
    {
        $build->load(['project', 'profile']);
        $profile = $build->profile;
        $project = $build->project;

        return [
            'id' => $build->id,
            'branch' => $build->branch,
            'commit_sha' => $build->commit_sha,
            'parameters' => $build->parameters ?? (object) [],
            'queued_at' => $build->queued_at?->toIso8601String(),
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'repo_url' => $project->repo_url,
                'default_branch' => $project->default_branch,
            ],
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'slug' => $profile->slug,
                'timeout_seconds' => (int) $profile->timeout_seconds,
                'artifact_patterns' => $profile->artifact_patterns ?? [],
                'env' => $profile->env ?? (object) [],
                'parameters' => $profile->parameterDefinitions(),
                'auto_submit' => (bool) $profile->auto_submit,
                'submit_config' => $profile->auto_submit
                    ? ($profile->submit_config ?? (object) [])
                    : null,
            ],
            'script' => $profile->build_script,
            'working_directory' => $profile->effectiveWorkingDirectory($project),
            'secrets' => $project->secrets()
                ->orderBy('key')
                ->get()
                ->mapWithKeys(fn (ProjectSecret $secret) => [$secret->key => $secret->value])
                ->all() ?: (object) [],
            'secret_files' => $project->secretFiles()
                ->orderBy('path')
                ->get()
                ->map(fn (ProjectSecretFile $file) => [
                    'path' => $file->path,
                    'content_base64' => base64_encode($file->content),
                    'sha256' => $file->sha256,
                    'size' => (int) $file->size,
                ])
                ->values()
                ->all(),
            'lease_seconds' => (int) config('builds.lease_seconds'),
            'heartbeat_interval_seconds' => 15,
            'log_flush_interval_ms' => 1000,
            'log_batch_max_events' => (int) config('builds.log_max_batch_events'),
            'artifact_max_megabytes' => (int) config('builds.artifact_max_megabytes'),
        ];
    }
}
