<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProjectSecretFileRequest;
use App\Http\Requests\Api\V1\StoreProjectSecretRequest;
use App\Http\Requests\Api\V1\UpdateProjectSecretRequest;
use App\Http\Resources\ProjectSecretFileResource;
use App\Http\Resources\ProjectSecretResource;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\ProjectSecretFile;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectSecretController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Lists secret names and file metadata. Values are never returned.
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manage-platform');

        return response()->json([
            'secrets' => ProjectSecretResource::collection(
                $project->secrets()->orderBy('key')->get()
            ),
            'secret_files' => ProjectSecretFileResource::collection(
                $project->secretFiles()->orderBy('path')->get()
            ),
        ]);
    }

    public function store(StoreProjectSecretRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();

        $secret = ProjectSecret::create([
            'project_id' => $project->id,
            'key' => $data['key'],
            'value' => $data['value'],
        ]);

        $this->audit->log($request->user(), 'project.secret_created', $project, [
            'project_id' => $project->id,
            'key' => $secret->key,
        ]);

        return response()->json(['secret' => new ProjectSecretResource($secret)], 201);
    }

    public function update(UpdateProjectSecretRequest $request, ProjectSecret $secret): JsonResponse
    {
        $secret->forceFill(['value' => $request->validated()['value']])->save();

        $this->audit->log($request->user(), 'project.secret_updated', $secret->project, [
            'project_id' => $secret->project_id,
            'key' => $secret->key,
        ]);

        return response()->json(['secret' => new ProjectSecretResource($secret)]);
    }

    public function destroy(Request $request, ProjectSecret $secret): JsonResponse
    {
        $this->authorize('manage-platform');

        $this->audit->log($request->user(), 'project.secret_deleted', $secret->project, [
            'project_id' => $secret->project_id,
            'key' => $secret->key,
        ]);

        $secret->delete();

        return response()->json(['message' => 'Segredo removido.']);
    }

    public function storeFile(StoreProjectSecretFileRequest $request, Project $project): JsonResponse
    {
        $upload = $request->file('file');
        $content = (string) $upload->get();

        $secretFile = ProjectSecretFile::create([
            'project_id' => $project->id,
            'path' => $request->validated()['path'],
            'content' => $content,
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
        ]);

        $this->audit->log($request->user(), 'project.secret_file_uploaded', $project, [
            'project_id' => $project->id,
            'path' => $secretFile->path,
            'size' => $secretFile->size,
        ]);

        return response()->json(['secret_file' => new ProjectSecretFileResource($secretFile)], 201);
    }

    public function destroyFile(Request $request, ProjectSecretFile $secretFile): JsonResponse
    {
        $this->authorize('manage-platform');

        $this->audit->log($request->user(), 'project.secret_file_deleted', $secretFile->project, [
            'project_id' => $secretFile->project_id,
            'path' => $secretFile->path,
        ]);

        $secretFile->delete();

        return response()->json(['message' => 'Arquivo secreto removido.']);
    }
}
