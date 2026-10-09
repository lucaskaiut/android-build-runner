<?php

namespace App\Console\Commands;

use App\Enums\RunnerStatus;
use App\Models\Runner;
use Illuminate\Console\Command;

class RevokeRunner extends Command
{
    protected $signature = 'runner:revoke {runner : ID ou nome do runner}';

    protected $description = 'Desativa um runner e revoga todos os seus tokens';

    public function handle(): int
    {
        $identifier = (string) $this->argument('runner');
        $query = Runner::query();

        if (ctype_digit($identifier)) {
            $query->where('id', (int) $identifier);
        } else {
            $query->where('name', $identifier);
        }

        $runner = $query->first();

        if ($runner === null) {
            $this->error('Runner não encontrado.');

            return self::FAILURE;
        }

        $runner->tokens()->delete();
        $runner->forceFill([
            'status' => RunnerStatus::Disabled,
            'revoked_at' => now(),
            'current_build_id' => null,
        ])->save();

        $this->info("Runner #{$runner->id} \"{$runner->name}\" desativado e tokens revogados.");

        return self::SUCCESS;
    }
}
