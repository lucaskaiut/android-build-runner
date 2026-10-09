<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProjectRequest;
use App\Http\Requests\Api\V1\UpdateProjectRequest;
use App\Http\Resources\GithubWebhookDeliveryResource;
use App\Http\Resources\ProjectResource;
use App\Http\Responses\PaginatedJson;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Project::query()
            ->withCount(['buildProfiles', 'builds'])
            ->with('latestBuild');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($inner) use ($search): void {
                $inner->where('name', 'like', '%'.$search.'%')
                    ->orWhere('slug', 'like', '%'.$search.'%')
                    ->orWhere('repo_url', 'like', '%'.$search.'%');
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return PaginatedJson::make(
            $query->orderBy('name')->paginate($perPage),
            ProjectResource::class,
        );
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $data = $request->validated();

        $project = Project::create([
            ...$data,
            'is_active' => $data['is_active'] ?? true,
            'webhook_enabled' => $data['webhook_enabled'] ?? false,
            'allowed_branches' => $data['allowed_branches'] ?? [],
        ]);

        $this->audit->log($request->user(), 'project.created', $project, [
            'slug' => $project->slug,
        ]);

        return response()->json(['project' => new ProjectResource($project)], 201);
    }

    public function show(Project $project): JsonResponse
    {
        $project->loadCount(['buildProfiles', 'builds'])
            ->load(['latestBuild', 'branchTriggers.profile']);

        return response()->json([
            'project' => new ProjectResource($project),
            'profiles' => \App\Http\Resources\BuildProfileResource::collection(
                $project->buildProfiles()->withCount('builds')->orderBy('name')->get()
            ),
            'branch_triggers' => \App\Http\Resources\ProjectBranchTriggerResource::collection($project->branchTriggers),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();
        $before = $project->only(['name', 'slug', 'repo_url', 'default_branch', 'working_directory']);

        $project->fill($data)->save();

        $changed = array_keys(array_diff_assoc(
            array_map(fn ($value) => is_array($value) ? json_encode($value) : (string) $value, $before),
            array_map(fn ($value) => is_array($value) ? json_encode($value) : (string) $value, $project->only(array_keys($before))),
        ));

        $this->audit->log($request->user(), 'project.updated', $project, [
            'changed' => $changed,
        ]);

        return response()->json(['project' => new ProjectResource($project->loadCount(['buildProfiles', 'builds']))]);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        if ($project->hasBuilds()) {
            throw ValidationException::withMessages([
                'project' => 'Este projeto possui histórico de builds e não pode ser excluído. Desative-o.',
            ]);
        }

        $this->audit->log($request->user(), 'project.deleted', null, [
            'project_id' => $project->id,
            'slug' => $project->slug,
        ]);

        $project->delete();

        return response()->json(['message' => 'Projeto removido.']);
    }

    public function activate(Request $request, Project $project): JsonResponse
    {
        $project->forceFill(['is_active' => true])->save();
        $this->audit->log($request->user(), 'project.activated', $project);

        return response()->json(['project' => new ProjectResource($project)]);
    }

    public function deactivate(Request $request, Project $project): JsonResponse
    {
        $project->forceFill(['is_active' => false, 'webhook_enabled' => false])->save();
        $this->audit->log($request->user(), 'project.deactivated', $project);

        return response()->json(['project' => new ProjectResource($project)]);
    }

    public function rotateWebhookSecret(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manage-platform');

        $secret = Str::random(64);

        $project->forceFill([
            'webhook_secret' => $secret,
            'webhook_enabled' => true,
        ])->save();

        $this->audit->log($request->user(), 'project.webhook_secret_rotated', $project, [
            'project_id' => $project->id,
        ]);

        // The secret is returned exactly once; it is never exposed again.
        return response()->json([
            'message' => 'Novo segredo gerado. Copie-o agora — ele não será exibido novamente.',
            'webhook_secret' => $secret,
            'webhook_path' => '/api/webhooks/github/'.$project->slug,
            'project' => new ProjectResource($project),
        ]);
    }

    public function webhookDeliveries(Request $request, Project $project): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return PaginatedJson::make(
            $project->webhookDeliveries()->orderByDesc('id')->paginate($perPage),
            GithubWebhookDeliveryResource::class,
        );
    }
}
