<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveBuildProfileRequest;
use App\Http\Resources\BuildProfileResource;
use App\Models\BuildProfile;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BuildProfileController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Project $project): JsonResponse
    {
        return response()->json([
            'profiles' => BuildProfileResource::collection(
                $project->buildProfiles()->withCount('builds')->orderBy('name')->get()
            ),
        ]);
    }

    public function store(SaveBuildProfileRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();

        $profile = new BuildProfile([
            ...$data,
            'project_id' => $project->id,
            'timeout_seconds' => $data['timeout_seconds'] ?? (int) config('builds.default_timeout_seconds'),
            'artifact_patterns' => $data['artifact_patterns'] ?? [],
            'env' => $data['env'] ?? [],
            'parameters' => $data['parameters'] ?? [],
            'is_active' => $data['is_active'] ?? true,
            'working_directory' => $data['working_directory'] ?? null,
        ]);
        $profile->save();

        $this->audit->log($request->user(), 'profile.created', $profile, [
            'project_id' => $project->id,
            'slug' => $profile->slug,
        ]);

        return response()->json(['profile' => new BuildProfileResource($profile)], 201);
    }

    public function update(SaveBuildProfileRequest $request, BuildProfile $profile): JsonResponse
    {
        $data = $request->validated();

        $tracked = ['name', 'slug', 'working_directory', 'timeout_seconds', 'artifact_patterns', 'env', 'parameters', 'is_active', 'build_script'];
        $before = $profile->only($tracked);

        $profile->fill($data)->save();

        $changed = array_keys(array_diff_assoc(
            array_map(fn ($value) => is_array($value) ? json_encode($value) : (string) $value, $before),
            array_map(fn ($value) => is_array($value) ? json_encode($value) : (string) $value, $profile->only($tracked)),
        ));

        $this->audit->log($request->user(), 'profile.updated', $profile, [
            'project_id' => $profile->project_id,
            'changed' => $changed,
            'script_changed' => in_array('build_script', $changed, true),
        ]);

        return response()->json(['profile' => new BuildProfileResource($profile->loadCount('builds'))]);
    }

    public function destroy(Request $request, BuildProfile $profile): JsonResponse
    {
        if ($profile->builds()->exists()) {
            throw ValidationException::withMessages([
                'profile' => 'Este perfil possui builds no histórico e não pode ser excluído. Desative-o.',
            ]);
        }

        $this->audit->log($request->user(), 'profile.deleted', null, [
            'profile_id' => $profile->id,
            'project_id' => $profile->project_id,
            'slug' => $profile->slug,
        ]);

        $profile->delete();

        return response()->json(['message' => 'Perfil removido.']);
    }

    public function activate(Request $request, BuildProfile $profile): JsonResponse
    {
        $this->authorize('manage-platform');

        $profile->forceFill(['is_active' => true])->save();
        $this->audit->log($request->user(), 'profile.activated', $profile);

        return response()->json(['profile' => new BuildProfileResource($profile)]);
    }

    public function deactivate(Request $request, BuildProfile $profile): JsonResponse
    {
        $this->authorize('manage-platform');

        $profile->forceFill(['is_active' => false])->save();
        $this->audit->log($request->user(), 'profile.deactivated', $profile);

        return response()->json(['profile' => new BuildProfileResource($profile)]);
    }
}
