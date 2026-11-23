<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionDelayHold;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class CollectionDelayHoldTest extends TestCase
{
    public function test_the_hold_lasts_one_delay_after_processing_resumes(): void
    {
        Cache::forget('nntmux:collections_resumed_at');
        $this->assertFalse(CollectionDelayHold::active(2));

        CollectionDelayHold::resume();
        $this->assertTrue(CollectionDelayHold::active(2));

        $this->travel(119)->minutes();
        $this->assertTrue(CollectionDelayHold::active(2));

        $this->travel(2)->minutes();
        $this->assertFalse(CollectionDelayHold::active(2));
    }
}
