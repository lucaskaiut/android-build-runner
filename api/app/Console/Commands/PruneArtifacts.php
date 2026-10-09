<?php

namespace App\Console\Commands;

use App\Services\ArtifactService;
use Illuminate\Console\Command;

class PruneArtifacts extends Command
{
    protected $signature = 'artifacts:prune
                            {--days= : Retenção em dias (padrão: BUILD_ARTIFACT_RETENTION_DAYS)}
                            {--dry-run : Apenas mostra o que seria removido}';

    protected $description = 'Remove artefatos de builds finalizadas além do período de retenção';

    public function handle(ArtifactService $artifacts): int
    {
        $days = (int) ($this->option('days') ?: config('builds.artifact_retention_days'));
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 1) {
            $this->error('Informe um número de dias válido (>= 1).');

            return self::FAILURE;
        }

        $result = $artifacts->prune($days, $dryRun);

        $this->info(sprintf(
            '%s %d artefatos (%.2f MB) de builds finalizadas há mais de %d dias.',
            $dryRun ? 'Seriam removidos' : 'Removidos',
            $result['artifacts'],
            $result['bytes'] / 1024 / 1024,
            $days,
        ));

        if ($dryRun) {
            $this->line('Execução em modo simulação — nada foi alterado.');
        }

        return self::SUCCESS;
    }
}
