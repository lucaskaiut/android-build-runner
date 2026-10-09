<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BuildStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BuildResource;
use App\Http\Resources\RunnerResource;
use App\Models\Build;
use App\Models\Project;
use App\Models\Runner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $byStatus = Build::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $statuses = [];

        foreach (BuildStatus::cases() as $status) {
            $statuses[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        $recentBuilds = Build::query()
            ->with(['project:id,name,slug', 'profile:id,name,slug', 'runner:id,name'])
            ->withCount('artifacts')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $runners = Runner::query()->with('currentBuild')->orderBy('name')->get();

        return response()->json([
            'stats' => [
                'by_status' => $statuses,
                'total_builds' => array_sum($statuses),
                'projects_count' => Project::count(),
                'active_projects_count' => Project::where('is_active', true)->count(),
                'runners_online' => $runners->filter(fn (Runner $runner) => $runner->isEffectivelyOnline())->count(),
                'runners_total' => $runners->count(),
            ],
            'recent_builds' => BuildResource::collection($recentBuilds),
            'runners' => RunnerResource::collection($runners),
        ]);
    }
}
