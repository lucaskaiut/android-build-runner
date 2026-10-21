<?php

namespace App\Console\Commands;

use App\Models\Build;
use App\Models\Project;
use App\Models\Runner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DiagnosePlatform extends Command
{
    protected $signature = 'platform:diagnose';

    protected $description = 'Mostra o estado do ambiente, banco de dados, armazenamento e fila de builds';

    public function handle(): int
    {
        $this->info('=== Local Build Platform — diagnóstico ===');
        $this->newLine();

        $this->line('PHP: '.PHP_VERSION);
        $this->line('Laravel: '.app()->version());
        $this->line('Ambiente: '.config('app.env').' — URL: '.config('app.url'));
        $this->line('Timezone: '.config('app.timezone'));
        $this->newLine();

        try {
            $this->line('MySQL: '.DB::selectOne('select version() as version')->version);
            $this->line('Banco: '.DB::connection()->getDatabaseName());
        } catch (\Throwable $exception) {
            $this->error('MySQL indisponível: '.$exception->getMessage());
        }

        $storagePath = storage_path('app/artifacts');
        $this->line('Diretório de artefatos: '.$storagePath.' ('.(is_writable($storagePath) ? 'gravável' : 'SEM PERMISSÃO DE ESCRITA').')');
        $this->newLine();

        $this->line('Projetos: '.Project::count().' ('.Project::where('is_active', true)->count().' ativos)');
        $this->line('Perfis: '.\App\Models\BuildProfile::count());
        $this->line('Builds: '.Build::count());

        $byStatus = Build::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        foreach ($byStatus as $status => $total) {
            $this->line("  - {$status}: {$total}");
        }

        foreach (Runner::orderBy('name')->get() as $runner) {
            $this->line(sprintf(
                'Runner "%s": %s — último heartbeat: %s',
                $runner->name,
                $runner->effectiveStatus()->label(),
                $runner->last_heartbeat_at?->toIso8601String() ?? 'nunca',
            ));
        }

        $this->newLine();
        $this->line('Lease de builds: '.config('builds.lease_seconds').'s');
        $this->line('Runner offline após: '.config('builds.runner_offline_seconds').'s');
        $this->line('Retenção de artefatos: '.config('builds.artifact_retention_days').' dias');
        $this->line('Retenção de logs: '.config('builds.log_retention_days').' dias');
        $this->newLine();
        $this->line('Agendador: aguarde "php artisan schedule:work" (serviço scheduler do compose).');
        $this->line('Submit automático: worker "php artisan builds:submit" no serviço scheduler.');
        $this->line('Comandos de manutenção: builds:reap, builds:submit, artifacts:prune, logs:prune');

        return self::SUCCESS;
    }
}
