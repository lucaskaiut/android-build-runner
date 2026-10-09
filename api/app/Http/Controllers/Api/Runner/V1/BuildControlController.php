<?php

namespace App\Http\Controllers\Api\Runner\V1;

use App\Exceptions\BuildConflictException;
use App\Http\Controllers\Controller;
use App\Models\Build;
use App\Models\Runner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuildControlController extends Controller
{
    public function show(Request $request, Build $build): JsonResponse
    {
        /** @var Runner $runner */
        $runner = $request->user();

        if ((int) $build->runner_id !== (int) $runner->id) {
            throw BuildConflictException::notOwnedByRunner();
        }

        return response()->json([
            'build_id' => $build->id,
            'control' => [
                'status' => $build->status->value,
                'status_label' => $build->status->label(),
                'cancel_requested' => $build->isCancelRequested(),
                'lease_seconds' => (int) config('builds.lease_seconds'),
            ],
        ]);
    }
}
