<?php

namespace App\Http\Controllers\Api\Runner\V1;

use App\Enums\RunnerStatus;
use App\Http\Controllers\Controller;
use App\Models\Build;
use App\Models\Runner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeartbeatController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'software_version' => ['nullable', 'string', 'max:64'],
            'protocol_version' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'os_info' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
            'current_build_id' => ['nullable', 'integer'],
        ]);

        /** @var Runner $runner */
        $runner = $request->user();

        if (($data['protocol_version'] ?? 1) !== 1) {
            return response()->json([
                'message' => 'Versão de protocolo não suportada pela API (esperado: 1).',
                'protocol_version' => 1,
            ], 426);
        }

        $runner->forceFill([
            'status' => RunnerStatus::Online,
            'software_version' => $data['software_version'] ?? $runner->software_version,
            'protocol_version' => $data['protocol_version'] ?? $runner->protocol_version,
            'os_info' => $data['os_info'] ?? $runner->os_info,
            'metadata' => $data['metadata'] ?? $runner->metadata,
            'last_heartbeat_at' => now(),
        ])->save();

        $abortCurrentBuild = false;
        $currentBuild = null;

        if ($runner->current_build_id !== null) {
            $build = Build::find($runner->current_build_id);

            if ($build === null
                || (int) $build->runner_id !== (int) $runner->id
                || $build->isTerminal()) {
                $runner->forceFill(['current_build_id' => null])->save();
                $abortCurrentBuild = $build !== null;
            } else {
                $currentBuild = [
                    'id' => $build->id,
                    'status' => $build->status->value,
                    'cancel_requested' => $build->isCancelRequested(),
                ];
            }
        }

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'runner' => [
                'id' => $runner->id,
                'status' => $runner->status->value,
            ],
            'current_build' => $currentBuild,
            'abort_current_build' => $abortCurrentBuild,
            'next_poll_seconds' => 10,
            'lease_seconds' => (int) config('builds.lease_seconds'),
        ]);
    }
}
