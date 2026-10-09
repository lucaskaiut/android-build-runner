<?php

namespace App\Console\Commands;

use App\Models\Runner;
use Illuminate\Console\Command;

class RotateRunnerToken extends Command
{
    protected $signature = 'runner:rotate-token {runner : ID ou nome do runner}';

    protected $description = 'Revoga os tokens atuais do runner e gera um novo token';

    public function handle(): int
    {
        $runner = $this->resolveRunner((string) $this->argument('runner'));

        if ($runner === null) {
            $this->error('Runner não encontrado.');

            return self::FAILURE;
        }

        $runner->tokens()->delete();

        $token = $runner->createToken('runner', ['runner']);
        $runner->forceFill(['token_rotated_at' => now()])->save();

        $this->info("Token rotacionado para o runner #{$runner->id} \"{$runner->name}\".");
        $this->newLine();
        $this->warn('Novo token (exibido apenas uma vez):');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }

    private function resolveRunner(string $identifier): ?Runner
    {
        $query = Runner::query();

        if (ctype_digit($identifier)) {
            $query->where('id', (int) $identifier);
        } else {
            $query->where('name', $identifier);
        }

        return $query->first();
    }
}
