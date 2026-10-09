<?php

namespace App\Services;

use App\Enums\LogStream;
use App\Models\Build;
use App\Models\BuildLog;
use Illuminate\Support\Facades\Log;

class LogIngestService
{
    /**
     * Appends a platform generated event to the build log stream.
     */
    public function appendSystem(Build $build, string $message): BuildLog
    {
        return BuildLog::create([
            'build_id' => $build->id,
            'batch_id' => null,
            'line_no' => 0,
            'stream' => LogStream::System->value,
            'message' => $this->truncate($message),
            'created_at' => now(),
        ]);
    }

    /**
     * Idempotent batch ingestion. Retries must reuse the same batch id and
     * line numbers; duplicates are ignored by the unique index.
     *
     * @param  array<int, array{line_no: int, stream: string, message: string}>  $events
     * @return array{accepted: int, ignored: int, truncated: bool}
     */
    public function ingest(Build $build, string $batchId, array $events): array
    {
        $maxLines = (int) config('builds.log_max_lines_per_build');
        $existing = BuildLog::where('build_id', $build->id)->count();

        if ($existing >= $maxLines) {
            return ['accepted' => 0, 'ignored' => count($events), 'truncated' => true];
        }

        $now = now();
        $rows = [];
        $seen = [];

        foreach ($events as $event) {
            $lineNo = (int) $event['line_no'];

            if (isset($seen[$lineNo])) {
                continue;
            }

            $seen[$lineNo] = true;

            $rows[] = [
                'build_id' => $build->id,
                'batch_id' => $batchId,
                'line_no' => $lineNo,
                'stream' => $event['stream'],
                'message' => $this->truncate((string) $event['message']),
                'created_at' => $now,
            ];
        }

        $accepted = 0;

        foreach (array_chunk($rows, 200) as $chunk) {
            $accepted += BuildLog::insertOrIgnore($chunk);
        }

        if ($accepted < count($rows)) {
            // Duplicated delivery of the same batch - harmless.
            Log::debug('build.logs.duplicate_batch', ['build_id' => $build->id, 'batch_id' => $batchId]);
        }

        return [
            'accepted' => $accepted,
            'ignored' => count($events) - $accepted,
            'truncated' => ($existing + $accepted) >= $maxLines,
        ];
    }

    private function truncate(string $message): string
    {
        $max = (int) config('builds.log_max_message_bytes');

        if (strlen($message) <= $max) {
            return $message;
        }

        return mb_strcut($message, 0, $max, 'UTF-8').' …[truncado]';
    }
}
