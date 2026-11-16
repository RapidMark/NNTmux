<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CollectionCleanupService;
use App\Services\CollectionDelayReset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('collections:restart-delay')]
#[Description('Restart the delay of waiting collections after downtime (started by tmux:start)')]
final class RestartCollectionDelay extends Command
{
    public function handle(CollectionCleanupService $cleanup): int
    {
        CollectionDelayReset::begin();
        try {
            $count = $cleanup->restartCollectionDelay();
            $this->info("Reset {$count} expired collections");
        } finally {
            CollectionDelayReset::end();
        }

        return self::SUCCESS;
    }
}
