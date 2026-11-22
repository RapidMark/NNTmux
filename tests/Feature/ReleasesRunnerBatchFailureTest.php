<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConfigurationDomain;
use App\Services\Runners\ReleasesRunner;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

final class ReleasesRunnerBatchFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.stream_fork_output' => false,
        ]);
        DB::purge('sqlite');

        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name TEXT, active INTEGER, backfill INTEGER)');
        DB::statement('CREATE TABLE collections (id INTEGER PRIMARY KEY, groups_id INTEGER)');
        foreach (range(1, 5) as $id) {
            DB::table('usenet_groups')->insert(['id' => $id, 'name' => "alt.binaries.g{$id}", 'active' => 1, 'backfill' => 0]);
            DB::table('collections')->insert(['id' => $id, 'groups_id' => $id]);
        }
        ConfigurationTestBuilder::update(ConfigurationDomain::Ingestion, ['release_threads' => 2]);
    }

    public function test_a_failed_batch_does_not_skip_the_later_batches(): void
    {
        $runner = new class extends ReleasesRunner
        {
            /** @var list<list<int|string>> */
            public array $batches = [];

            protected function runParallelCommands(array $commands, int $maxProcesses, ?int $timeout = null): array
            {
                $this->batches[] = array_keys($commands);
                if (array_key_exists(1, $commands)) {
                    throw new RuntimeException('Worker batch did not succeed: 1 timeout');
                }

                return array_fill_keys(array_keys($commands), '');
            }
        };

        ob_start();
        try {
            $runner->releases();
            $this->fail('The failed batch was not reported.');
        } catch (RuntimeException $e) {
            $this->assertSame('Worker batch failed.', $e->getMessage());
        } finally {
            ob_end_clean();
        }

        $this->assertSame([[1, 2], [3, 4], [5]], $runner->batches);
    }
}
