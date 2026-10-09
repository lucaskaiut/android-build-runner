<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\Runner;
use App\Services\BuildClaimService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Proves MySQL row locking keeps claim atomic. Runs real separate PHP
 * processes against the test database.
 */
class BuildClaimConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        // Data created here is committed (child processes need to see it), so
        // it must be removed explicitly to keep later test classes isolated.
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    private function helperPath(): string
    {
        return base_path('tests/Support/concurrency_helper.php');
    }

    private function childEnv(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_DATABASE' => 'build_platform_test',
        ];
    }

    private function tempFile(string $suffix): string
    {
        return sys_get_temp_dir().'/lbp-'.bin2hex(random_bytes(6)).'-'.$suffix;
    }

    public function test_claim_skips_rows_locked_by_another_session(): void
    {
        $runner = Runner::factory()->create();
        $build = Build::factory()->create(['status' => BuildStatus::Queued, 'queued_at' => now()]);

        $lockerOutput = $this->tempFile('locker');

        $locker = new Process(
            ['php', $this->helperPath(), 'lock', $this->tempFile('barrier'), $lockerOutput, '5'],
            base_path(),
            $this->childEnv(),
        );
        $locker->start();

        $deadline = microtime(true) + 15;
        while (! file_exists($lockerOutput)) {
            if (microtime(true) > $deadline) {
                $this->fail('A linha não foi bloqueada pelo processo auxiliar a tempo.');
            }
            usleep(20000);
        }

        $this->assertSame('locked', file_get_contents($lockerOutput));

        // The row is locked: SKIP LOCKED must skip it and return no work.
        $claimed = app(BuildClaimService::class)->claim($runner);

        $this->assertNull($claimed, 'O claim não pode bloquear nem receber uma linha bloqueada.');

        $locker->wait();

        $build->refresh();
        $this->assertSame(BuildStatus::Queued, $build->status);
        $this->assertNull($build->runner_id);
    }

    public function test_two_simultaneous_claims_never_receive_the_same_build(): void
    {
        $runnerA = Runner::factory()->create();
        $runnerB = Runner::factory()->create();

        $build = Build::factory()->create(['status' => BuildStatus::Queued, 'queued_at' => now()]);

        $barrier = $this->tempFile('go');
        $outputA = $this->tempFile('a');
        $outputB = $this->tempFile('b');

        $processA = new Process(['php', $this->helperPath(), 'claim', (string) $runnerA->id, $barrier, $outputA], base_path(), $this->childEnv());
        $processB = new Process(['php', $this->helperPath(), 'claim', (string) $runnerB->id, $barrier, $outputB], base_path(), $this->childEnv());

        $processA->start();
        $processB->start();

        // Give both processes time to boot and wait on the barrier.
        usleep(500000);
        touch($barrier);

        $processA->wait();
        $processB->wait();

        $this->assertTrue($processA->isSuccessful(), 'Processo A falhou: '.$processA->getErrorOutput());
        $this->assertTrue($processB->isSuccessful(), 'Processo B falhou: '.$processB->getErrorOutput());

        $results = [file_get_contents($outputA), file_get_contents($outputB)];
        $assigned = array_values(array_filter($results, fn (string $result) => $result !== 'null'));

        $this->assertCount(
            1,
            $assigned,
            'Exatamente um runner deve receber a build. Resultados: '.json_encode($results),
        );

        $this->assertSame((string) $build->id, $assigned[0]);

        $build->refresh();
        $this->assertSame(BuildStatus::Preparing, $build->status);
        $this->assertContains($build->runner_id, [$runnerA->id, $runnerB->id]);
    }
}
