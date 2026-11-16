<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Marks the startup reset of collection delays as running.
 *
 * tmux:start restarts the delay of every waiting collection after downtime so
 * half-received posts are not released at once. With a large backlog that takes
 * minutes, so it runs in the background while the workers start; until it ends,
 * release processing does not cut incomplete collections short. Complete
 * collections are released as usual.
 */
final class CollectionDelayReset
{
    private const string KEY = 'nntmux:collection_delay_reset_running';

    // Released by the reset itself; the TTL only covers a reset that died.
    private const int MAX_HOURS = 6;

    public static function begin(): void
    {
        Cache::put(self::KEY, true, now()->addHours(self::MAX_HOURS));
    }

    public static function end(): void
    {
        Cache::forget(self::KEY);
    }

    public static function running(): bool
    {
        return (bool) Cache::get(self::KEY, false);
    }
}
