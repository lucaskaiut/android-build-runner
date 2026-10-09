<?php

namespace App\Http\Controllers\Api\Runner\V1;

use App\Enums\BuildStatus;
use App\Exceptions\BuildConflictException;
use App\Http\Controllers\Controller;
use App\Models\Build;
use App\Models\Runner;
use App\Services\BuildLifecycleService;
use App\Services\LogIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BuildExecutionController extends Controller
{
    public function __construct(
        private readonly BuildLifecycleService $lifecycle,
        private readonly LogIngestService $logs,
    ) {}

    public function start(Request $request, Build $build): JsonResponse
    {
        $data = $request->validate([
            'resolved_sha' => ['nullable', 'string', 'max:40'],
        ]);

        /** @var Runner $runner */
        $runner = $request->user();

        $build = $this->lifecycle->start($build, $runner, $data['resolved_sha'] ?? null);

        return response()->json([
            'build' => [
                'id' => $build->id,
                'status' => $build->status->value,
                'commit_sha' => $build->commit_sha,
                'lease_seconds' => (int) config('builds.lease_seconds'),
            ],
        ]);
    }

    public function logs(Request $request, Build $build): JsonResponse
    {
        $data = $request->validate([
            'batch_id' => ['required', 'string', 'max:64'],
            'events' => ['required', 'array', 'max:'.(int) config('builds.log_max_batch_events')],
            'events.*.line_no' => ['required', 'integer', 'min:0', 'max:65535'],
            'events.*.stream' => ['required', 'string', Rule::in(['stdout', 'stderr', 'system'])],
            'events.*.message' => ['present', 'nullable', 'string', 'max:65536'],
        ]);

        // Blank log lines are valid; ConvertEmptyStringsToNull turns them
        // into null, so normalize back to an empty string before persisting.
        $events = array_map(static function (array $event): array {
            $event['message'] = $event['message'] ?? '';

            return $event;
        }, $data['events']);

        /** @var Runner $runner */
        $runner = $request->user();

        $build = $this->lifecycle->heartbeat($build, $runner);

        if ($build->status->isTerminal()) {
            return $this->controlResponse($build, status: 409, extra: [
                'message' => 'A build já foi finalizada; interrompa a execução local.',
            ]);
        }

        $result = $this->logs->ingest($build, $data['batch_id'], $events);

        return $this->controlResponse($build, extra: $result);
    }

    public function heartbeat(Request $request, Build $build): JsonResponse
    {
        /** @var Runner $runner */
        $runner = $request->user();

        $build = $this->lifecycle->heartbeat($build, $runner);

        return $this->controlResponse($build);
    }

    public function complete(Request $request, Build $build): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(['success', 'failed', 'cancelled'])],
            'exit_code' => ['nullable', 'integer', 'min:-32768', 'max:32767'],
            'error_message' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var Runner $runner */
        $runner = $request->user();

        $build = $this->lifecycle->complete(
            $build,
            $runner,
            BuildStatus::from($data['status']),
            $data['exit_code'] ?? null,
            $data['error_message'] ?? null,
        );

        return response()->json([
            'build' => [
                'id' => $build->id,
                'status' => $build->status->value,
                'exit_code' => $build->exit_code,
                'error_message' => $build->error_message,
                'finished_at' => $build->finished_at?->toIso8601String(),
                'duration_ms' => $build->computedDurationMs(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function controlResponse(Build $build, int $status = 200, array $extra = []): JsonResponse
    {
        if ($build->status->isTerminal() && $status === 200) {
            $status = 409;
            $extra['message'] = $extra['message'] ?? 'A build já foi finalizada; interrompa a execução local.';
        }

        return response()->json([
            'build_id' => $build->id,
            'control' => [
                'status' => $build->status->value,
                'cancel_requested' => $build->isCancelRequested(),
                'lease_seconds' => (int) config('builds.lease_seconds'),
            ],
        ] + $extra, $status);
    }
}
