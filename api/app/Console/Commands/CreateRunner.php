<?php

namespace App\Console\Commands;

use App\Enums\RunnerStatus;
use App\Models\Runner;
use Illuminate\Console\Command;

class CreateRunner extends Command
{
    protected $signature = 'runner:create {name : Nome legível do runner (ex.: PC-BUILD-01)}
                            {--protocol=1 : Versão do protocolo do runner}';

    protected $description = 'Registra um runner e gera seu token de acesso (exibido uma única vez)';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '' || strlen($name) > 120) {
            $this->error('Informe um nome de runner válido (até 120 caracteres).');

            return self::FAILURE;
        }

        if (Runner::where('name', $name)->exists()) {
            $this->error("Já existe um runner chamado \"{$name}\".");

            return self::FAILURE;
        }

        $runner = Runner::create([
            'name' => $name,
            'status' => RunnerStatus::Offline,
            'protocol_version' => (int) $this->option('protocol'),
        ]);

        $token = $runner->createToken('runner', ['runner']);

        $this->info("Runner #{$runner->id} \"{$runner->name}\" registrado.");
        $this->newLine();
        $this->warn('Token de acesso (exibido apenas uma vez — guarde em local seguro):');
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->line('Configure no runner Windows (ex.: C:\ProgramData\LocalBuildPlatform\runner\config.json):');
        $this->line('  "token": "'.$token->plainTextToken.'"');

        return self::SUCCESS;
    }
}
