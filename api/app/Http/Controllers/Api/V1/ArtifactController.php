<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BuildArtifactResource;
use App\Models\Build;
use App\Models\BuildArtifact;
use App\Services\ArtifactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ArtifactController extends Controller
{
    public function __construct(
        private readonly ArtifactService $artifacts,
    ) {}

    public function index(Build $build): JsonResponse
    {
        return response()->json([
            'artifacts' => BuildArtifactResource::collection(
                $build->artifacts()->orderBy('original_name')->get()
            ),
        ]);
    }

    public function download(Request $request, BuildArtifact $artifact): BinaryFileResponse
    {
        $path = $this->artifacts->absolutePath($artifact);

        abort_if($path === null || ! is_file($path), 404, 'Artefato não encontrado.');

        return response()->download($path, $artifact->original_name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(false);
    }
}
