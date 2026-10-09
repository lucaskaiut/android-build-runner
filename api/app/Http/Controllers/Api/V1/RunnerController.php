<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RunnerResource;
use App\Models\Runner;
use Illuminate\Http\JsonResponse;

class RunnerController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'runners' => RunnerResource::collection(
                Runner::query()->with('currentBuild')->orderBy('name')->get()
            ),
        ]);
    }

    public function show(Runner $runner): JsonResponse
    {
        return response()->json([
            'runner' => new RunnerResource($runner->load('currentBuild')),
        ]);
    }
}
