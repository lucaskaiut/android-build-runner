<?php

namespace App\Console\Commands;

use App\Services\BuildLifecycleService;
use Illuminate\Console\Command;

class ReapBuilds extends Command
{
    protected $signature = 'builds:reap';

    protected $description = 'Reconcilia builds com lease expirada, timeouts e runners offline';

    public function handle(BuildLifecycleService $lifecycle): int
    {
        $result = $lifecycle->reap();

        $this->info(sprintf(
            'Reaper: %d builds com lease expirada, %d builds com timeout excedido, %d runners marcados offline.',
            $result['expired'],
            $result['timed_out'],
            $result['runners_offline'],
        ));

        return self::SUCCESS;
    }
}
