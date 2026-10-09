<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BuildStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BuildLogResource;
use App\Models\Build;
use App\Models\BuildLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BuildLogController extends Controller
{
    /**
     * Polling fallback / history endpoint.
     */
    public function index(Request $request, Build $build): JsonResponse
    {
        $afterId = max((int) $request->query('after_id', 0), 0);
        $limit = min(max((int) $request->query('limit', 300), 1), 1000);

        $logs = $build->logs()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'logs' => BuildLogResource::collection($logs),
            'last_id' => (int) ($logs->last()?->id ?? $afterId),
            'build_status' => $build->status->value,
        ]);
    }

    /**
     * Server-Sent Events stream. The client reconnects with ?after_id=<last id>
     * to resume without duplicating or losing events.
     */
    public function stream(Request $request, Build $build): StreamedResponse
    {
        $afterId = max((int) $request->query('after_id', 0), 0);
        $buildId = $build->id;

        return response()->stream(function () use ($buildId, $afterId): void {
            ignore_user_abort(true);
            set_time_limit(0);

            $cursor = $afterId;
            $lastStatus = null;
            $lastOutputAt = time();
            $startedAt = time();
            $maxStreamSeconds = 3600;

            while (true) {
                if (connection_aborted()) {
                    return;
                }

                $logs = BuildLog::query()
                    ->where('build_id', $buildId)
                    ->where('id', '>', $cursor)
                    ->orderBy('id')
                    ->limit(500)
                    ->get();

                foreach ($logs as $log) {
                    $cursor = (int) $log->id;
                    $this->emit('log', [
                        'id' => (int) $log->id,
                        'stream' => $log->stream,
                        'message' => $log->message,
                        'created_at' => $log->created_at?->toIso8601String(),
                    ]);
                }

                if ($logs->isNotEmpty()) {
                    $lastOutputAt = time();
                }

                $build = Build::query()->whereKey($buildId)->first(['id', 'status', 'exit_code', 'error_message', 'finished_at']);

                if ($build === null) {
                    $this->emit('end', ['status' => null]);

                    return;
                }

                if ($build->status->value !== $lastStatus) {
                    $lastStatus = $build->status->value;
                    $this->emit('status', [
                        'status' => $build->status->value,
                        'status_label' => $build->status->label(),
                        'exit_code' => $build->exit_code,
                        'error_message' => $build->error_message,
                        'finished_at' => $build->finished_at?->toIso8601String(),
                    ]);
                }

                if ($build->status->isTerminal()) {
                    $remaining = BuildLog::query()
                        ->where('build_id', $buildId)
                        ->where('id', '>', $cursor)
                        ->exists();

                    if (! $remaining) {
                        $this->emit('end', ['status' => $build->status->value]);

                        return;
                    }
                }

                if (time() - $lastOutputAt >= 15) {
                    echo ': ping '.time()."\n\n";
                    @flush();
                    $lastOutputAt = time();
                }

                if (time() - $startedAt >= $maxStreamSeconds) {
                    // Ask the client to reconnect with its cursor.
                    $this->emit('reconnect', ['after_id' => $cursor]);

                    return;
                }

                @flush();
                usleep(500000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emit(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
        @flush();
    }
}
