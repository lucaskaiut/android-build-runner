<?php

namespace App\Console\Commands;

use App\Enums\BuildStatus;
use App\Enums\SubmitStatus;
use App\Models\Build;
use App\Services\GooglePlaySubmitService;
use App\Services\LogIngestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProcessBuildSubmits extends Command
{
    protected $signature = 'builds:submit {--limit=5 : Quantidade máxima de submits processados por execução}';

    protected $description = 'Envia para a loja os builds bem-sucedidos com submit automático pendente';

    public function handle(
        GooglePlaySubmitService $submitter,
        LogIngestService $logs,
    ): int {
        $limit = max(1, min(20, (int) $this->option('limit')));

        $this->recoverExpiredLeases($logs);

        $candidates = Build::query()
            ->where('status', BuildStatus::Success->value)
            ->where('submit_status', SubmitStatus::Pending->value)
            ->whereNotNull('submit_config')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $processed = 0;

        foreach ($candidates as $candidate) {
            $build = $this->claim($candidate);

            if ($build === null) {
                continue;
            }

            $processed++;
            $this->info("Processando submit do build #{$build->id}...");
            $logs->appendSystem($build, 'Submit para a loja iniciado pela API.');

            try {
                $result = $submitter->submit($build);

                $message = "Submit concluído para a track {$result['track']} (versionCode {$result['version_code']}).";
                if (($result['warning'] ?? null) === 'changes_not_sent_for_review') {
                    $message .= ' Aviso: o Google não enviou as alterações para revisão; verifique o Play Console.';
                }

                $build->forceFill([
                    'submit_status' => SubmitStatus::Success,
                    'submit_message' => Str::limit($message, 2000),
                    'submit_version_code' => $result['version_code'],
                    'submitted_at' => now(),
                    'submit_lease_expires_at' => null,
                ])->save();

                $logs->appendSystem($build, $message);
                $this->info("Build #{$build->id}: {$message}");
            } catch (Throwable $exception) {
                $message = Str::limit('Submit falhou: '.$exception->getMessage(), 2000);

                $build->forceFill([
                    'submit_status' => SubmitStatus::Failed,
                    'submit_message' => $message,
                    'submit_version_code' => null,
                    'submitted_at' => now(),
                    'submit_lease_expires_at' => null,
                ])->save();

                $logs->appendSystem($build, $message);
                $this->error("Build #{$build->id}: {$message}");
            }
        }

        if ($processed > 0) {
            $this->info("Submits processados: {$processed}.");
        }

        return self::SUCCESS;
    }

    private function claim(Build $candidate): ?Build
    {
        return DB::transaction(function () use ($candidate): ?Build {
            $build = Build::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->first();

            if ($build === null
                || $build->status !== BuildStatus::Success
                || $build->submit_status !== SubmitStatus::Pending
                || $build->submit_config === null) {
                return null;
            }

            $build->forceFill([
                'submit_status' => SubmitStatus::Processing,
                'submit_message' => null,
                'submit_version_code' => null,
                'submit_started_at' => now(),
                'submit_lease_expires_at' => now()->addSeconds(
                    max(60, (int) config('builds.submit_timeout_seconds', 900))
                ),
                'submit_attempts' => ((int) $build->submit_attempts) + 1,
                'submitted_at' => null,
            ])->save();

            return $build;
        });
    }

    private function recoverExpiredLeases(LogIngestService $logs): void
    {
        $stale = Build::query()
            ->where('submit_status', SubmitStatus::Processing->value)
            ->whereNotNull('submit_lease_expires_at')
            ->where('submit_lease_expires_at', '<', now())
            ->get();

        foreach ($stale as $build) {
            $message = 'Submit interrompido antes da conclusão; verifique o Play Console.';

            $build->forceFill([
                'submit_status' => SubmitStatus::Failed,
                'submit_message' => $message,
                'submit_version_code' => null,
                'submitted_at' => now(),
                'submit_lease_expires_at' => null,
            ])->save();

            $logs->appendSystem($build, $message);
        }
    }
}
