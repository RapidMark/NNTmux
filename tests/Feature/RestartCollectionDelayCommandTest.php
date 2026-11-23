<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CollectionCleanupService;
use App\Services\CollectionDelayReset;
use Tests\TestCase;

class RestartCollectionDelayCommandTest extends TestCase
{
    public function test_the_flag_is_set_during_the_reset_and_cleared_after_it(): void
    {
        $seen = null;
        $this->app->instance(CollectionCleanupService::class, new class($seen) extends CollectionCleanupService
        {
            public function __construct(private mixed &$seen) {}

            public function restartCollectionDelay(): int
            {
                $this->seen = CollectionDelayReset::running();

                return 7;
            }
        });

        $this->artisan('collections:restart-delay')->assertSuccessful();

        $this->assertTrue($seen);
        $this->assertFalse(CollectionDelayReset::running());
    }

    public function test_the_flag_is_cleared_when_the_reset_fails(): void
    {
        $this->app->instance(CollectionCleanupService::class, new class extends CollectionCleanupService
        {
            public function __construct() {}

            public function restartCollectionDelay(): int
            {
                throw new \RuntimeException('db gone');
            }
        });

        try {
            $this->artisan('collections:restart-delay');
        } catch (\RuntimeException) {
        }

        $this->assertFalse(CollectionDelayReset::running());
    }
}
