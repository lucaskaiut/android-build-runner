<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectBranchTriggerResource;
use App\Models\Project;
use App\Models\ProjectBranchTrigger;
use App\Rules\BranchPattern;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectBranchTriggerController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Project $project): JsonResponse
    {
        return response()->json([
            'branch_triggers' => ProjectBranchTriggerResource::collection(
                $project->branchTriggers()->with('profile')->orderBy('branch_pattern')->get()
            ),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manage-platform');

        $data = $request->validate([
            'build_profile_id' => [
                'required', 'integer',
                Rule::exists('build_profiles', 'id')->where('project_id', $project->id),
            ],
            'branch_pattern' => [
                'required', 'string', 'max:255', new BranchPattern,
                Rule::unique('project_branch_triggers', 'branch_pattern')->where('project_id', $project->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'build_profile_id.exists' => 'O perfil selecionado não pertence a este projeto.',
            'branch_pattern.unique' => 'Já existe um gatilho para esta branch.',
        ]);

        $trigger = $project->branchTriggers()->create([
            'build_profile_id' => $data['build_profile_id'],
            'branch_pattern' => $data['branch_pattern'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->audit->log($request->user(), 'branch_trigger.created', $trigger, [
            'project_id' => $project->id,
            'branch_pattern' => $trigger->branch_pattern,
        ]);

        return response()->json(['branch_trigger' => new ProjectBranchTriggerResource($trigger->load('profile'))], 201);
    }

    public function update(Request $request, ProjectBranchTrigger $branchTrigger): JsonResponse
    {
        $this->authorize('manage-platform');

        $data = $request->validate([
            'build_profile_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('build_profiles', 'id')->where('project_id', $branchTrigger->project_id),
            ],
            'branch_pattern' => [
                'sometimes', 'required', 'string', 'max:255', new BranchPattern,
                Rule::unique('project_branch_triggers', 'branch_pattern')
                    ->where('project_id', $branchTrigger->project_id)
                    ->ignore($branchTrigger->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branchTrigger->fill($data)->save();

        $this->audit->log($request->user(), 'branch_trigger.updated', $branchTrigger, [
            'project_id' => $branchTrigger->project_id,
            'branch_pattern' => $branchTrigger->branch_pattern,
        ]);

        return response()->json(['branch_trigger' => new ProjectBranchTriggerResource($branchTrigger->load('profile'))]);
    }

    public function destroy(Request $request, ProjectBranchTrigger $branchTrigger): JsonResponse
    {
        $this->authorize('manage-platform');

        $this->audit->log($request->user(), 'branch_trigger.deleted', null, [
            'project_id' => $branchTrigger->project_id,
            'branch_pattern' => $branchTrigger->branch_pattern,
        ]);

        $branchTrigger->delete();

        return response()->json(['message' => 'Gatilho removido.']);
    }
}
