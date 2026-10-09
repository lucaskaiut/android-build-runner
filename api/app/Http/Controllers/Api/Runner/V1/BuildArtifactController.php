<?php

namespace App\Http\Controllers\Api\Runner\V1;

use App\Exceptions\BuildConflictException;
use App\Http\Controllers\Controller;
use App\Http\Resources\BuildArtifactResource;
use App\Models\Build;
use App\Models\Runner;
use App\Services\ArtifactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuildArtifactController extends Controller
{
    public function __construct(
        private readonly ArtifactService $artifacts,
    ) {}

    public function store(Request $request, Build $build): JsonResponse
    {
        /** @var Runner $runner */
        $runner = $request->user();

        if ((int) $build->runner_id !== (int) $runner->id) {
            throw BuildConflictException::notOwnedByRunner();
        }

        if ($build->status->isTerminal()) {
            throw BuildConflictException::invalidState(
                $build->status,
                'A build já foi finalizada; artefatos não podem mais ser enviados.',
            );
        }

        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.(((int) config('builds.artifact_max_megabytes')) * 1024),
            ],
            'sha256' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'relative_path' => ['nullable', 'string', 'max:500'],
        ]);

        $artifact = $this->artifacts->store(
            $build,
            $request->file('file'),
            $data['sha256'] ?? null,
            $data['relative_path'] ?? null,
        );

        return response()->json([
            'artifact' => new BuildArtifactResource($artifact),
        ], $artifact->wasRecentlyCreated ? 201 : 200);
    }
}
