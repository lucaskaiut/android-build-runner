<?php

/*
|--------------------------------------------------------------------------
| Concurrency helper for claim tests
|--------------------------------------------------------------------------
|
| Boots a fresh Laravel instance in a separate process so tests can exercise
| real concurrent MySQL sessions (row locks, SKIP LOCKED, idempotency).
|
| Usage:
|   php concurrency_helper.php claim  <runnerId> <barrierFile> <outputFile>
|   php concurrency_helper.php lock   <barrierFile> <outputFile> <sleepSeconds>
|
| The process must be started with DB_DATABASE=build_platform_test.
*/

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Build;
use App\Models\Runner;
use App\Services\BuildClaimService;
use Illuminate\Support\Facades\DB;

$mode = $argv[1] ?? '';

function waitForBarrier(string $file, int $timeoutSeconds = 20): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (! file_exists($file)) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "barrier timeout\n");
            exit(2);
        }

        usleep(5000);
    }
}

try {
    if ($mode === 'claim') {
        $runnerId = (int) $argv[2];
        $barrier = $argv[3];
        $output = $argv[4];

        waitForBarrier($barrier);

        $runner = Runner::findOrFail($runnerId);
        $build = app(BuildClaimService::class)->claim($runner);

        file_put_contents($output, $build === null ? 'null' : (string) $build->id);
        exit(0);
    }

    if ($mode === 'lock') {
        $barrier = $argv[2];
        $output = $argv[3];
        $sleep = (int) ($argv[4] ?? 3);

        DB::beginTransaction();
        $build = Build::query()
            ->where('status', 'queued')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($build === null) {
            DB::rollBack();
            file_put_contents($output, 'no-build');
            exit(1);
        }

        file_put_contents($output, 'locked');

        sleep($sleep);
        DB::rollBack();
        exit(0);
    }

    fwrite(STDERR, "unknown mode\n");
    exit(2);
} catch (Throwable $exception) {
    if (isset($output)) {
        file_put_contents($output, 'error:'.$exception->getMessage());
    }

    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
