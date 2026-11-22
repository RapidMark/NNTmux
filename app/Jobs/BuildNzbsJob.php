<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ReleaseProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Builds the NZBs of one small batch of releases from any group. The batch is
 * claimed, so workers never build the same NZB; releases not reached before the
 * time budget runs out are handed back for the next job.
 */
final class BuildNzbsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const string QUEUE = 'nzb-build';

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
        // Past the budget the job only hands claims back; stay below the
        // connection's retry_after so a running job is never handed out twice.
        $this->timeout = (int) config('nntmux.nzb_queue_job_seconds', 120) + 30;
    }

    public function handle(ReleaseProcessingService $releases): void
    {
        $releases->createNZBs(
            null,
            max(1, (int) config('nntmux.nzb_queue_batch', 25)),
            microtime(true) + max(1, (int) config('nntmux.nzb_queue_job_seconds', 120)),
        );
    }
}
