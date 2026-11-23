<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Holds back the cut of incomplete collections after processing was down.
 *
 * A post half-received when processing stopped looks older than the collection
 * delay when it starts again, and would be cut to the files present at once.
 * tmux:start records when processing resumed; for one delay after that, release
 * processing releases complete collections only. Rewriting dateadded on every
 * waiting collection did the same, but took hours on a large backlog.
 */
final class CollectionDelayHold
{
    private const string KEY = 'nntmux:collections_resumed_at';

    public static function resume(): void
    {
        Cache::forever(self::KEY, now()->timestamp);
    }

    public static function active(int $delayHours): bool
    {
        $resumedAt = Cache::get(self::KEY);

        return is_numeric($resumedAt) && (int) $resumedAt > now()->subHours($delayHours)->timestamp;
    }
}
