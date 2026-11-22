<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BuildNzbsJob;
use App\Services\Nzb\NzbCreationCandidateQuery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

#[Signature('nzb:dispatch')]
#[Description('Queue NZB builds while releases wait for an NZB (NN_NZB_QUEUE)')]
final class DispatchNzbBuilds extends Command
{
    public function handle(): int
    {
        // Two jobs per worker keep every worker busy between runs of this command.
        $wanted = max(1, (int) config('nntmux.nzb_queue_workers', 3)) * 2;
        $queued = Queue::size(BuildNzbsJob::QUEUE);
        $room = $wanted - $queued;
        if ($room <= 0) {
            return self::SUCCESS;
        }

        $batch = max(1, (int) config('nntmux.nzb_queue_batch', 25));
        $waiting = NzbCreationCandidateQuery::baseBuilder(null)->select('r.id')->limit($room * $batch)->get()->count();
        $jobs = min($room, (int) ceil($waiting / $batch));
        for ($i = 0; $i < $jobs; $i++) {
            BuildNzbsJob::dispatch();
        }
        if ($jobs > 0) {
            $this->info("Queued {$jobs} NZB build job(s) for {$waiting} waiting release(s).");
        }

        return self::SUCCESS;
    }
}
