<?php

namespace App\Console\Commands;

use App\Models\BuildLog;
use Illuminate\Console\Command;

class PruneLogs extends Command
{
    protected $signature = 'logs:prune
                            {--days= : Retenção em dias (padrão: BUILD_LOG_RETENTION_DAYS)}
                            {--dry-run : Apenas mostra o que seria removido}';

    protected $description = 'Remove logs de builds finalizadas além do período de retenção';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('builds.log_retention_days'));
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 1) {
            $this->error('Informe um número de dias válido (>= 1).');

            return self::FAILURE;
        }

        $threshold = now()->subDays($days);
        $total = 0;

        do {
            $query = BuildLog::query()
                ->where('created_at', '<', $threshold)
                ->whereHas('build', fn ($build) => $build->terminal());

            if ($dryRun) {
                $count = $query->count();
                $total += $count;
                break;
            }

            $deleted = $query->limit(10000)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        $this->info(sprintf(
            '%s %d registros de log anteriores a %s.',
            $dryRun ? 'Seriam removidos' : 'Removidos',
            $total,
            $threshold->toDateString(),
        ));

        return self::SUCCESS;
    }
}
