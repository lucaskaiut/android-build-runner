<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBuildRequest;
use App\Http\Resources\BuildResource;
use App\Http\Responses\PaginatedJson;
use App\Models\Build;
use App\Models\Project;
use App\Services\BuildLifecycleService;
use App\Services\BuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuildController extends Controller
{
    public function __construct(
        private readonly BuildService $builds,
        private readonly BuildLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Build::query()
            ->with(['project:id,name,slug', 'profile:id,name,slug', 'runner:id,name'])
            ->withCount('artifacts');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        if ($request->filled('build_profile_id')) {
            $query->where('build_profile_id', $request->integer('build_profile_id'));
        }

        if ($request->filled('status')) {
            $statuses = is_array($request->query('status'))
                ? $request->query('status')
                : explode(',', (string) $request->query('status'));
            $query->whereIn('status', array_intersect($statuses, \App\Enums\BuildStatus::values()));
        }

        if ($request->filled('source')) {
            $query->where('source', $request->query('source'));
        }

        if ($request->filled('branch')) {
            $query->where('branch', $request->query('branch'));
        }

        if ($request->filled('sha')) {
            $query->where('commit_sha', 'like', $request->query('sha').'%');
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to'));
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return PaginatedJson::make(
            $query->orderByDesc('id')->paginate($perPage),
            BuildResource::class,
        );
    }

    public function store(StoreBuildRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();

        $build = $this->builds->create(
            $project,
            $project->buildProfiles()->findOrFail($data['build_profile_id']),
            $data,
            \App\Enums\BuildSource::Manual,
            $request->user(),
        );

        return response()->json([
            'build' => new BuildResource($build->load(['project', 'profile'])),
        ], 201);
    }

    public function show(Build $build): JsonResponse
    {
        $build->load(['project', 'profile', 'runner', 'artifacts']);

        return response()->json(['build' => new BuildResource($build)]);
    }

    public function cancel(Request $request, Build $build): JsonResponse
    {
        $build = $this->lifecycle->cancel($build, $request->user());

        return response()->json([
            'build' => new BuildResource($build->load(['project', 'profile', 'runner'])),
        ]);
    }
}
