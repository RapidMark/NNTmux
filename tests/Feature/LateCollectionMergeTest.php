<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Models\Release;
use App\Services\CollectionCleanupService;
use App\Services\CollectionsCleaningService;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\Releases\LateCollectionMerger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class LateCollectionMergeTest extends TestCase
{
    private const HASH = 'aaaaaaaaaaaaaaaaaaaa';

    private const POSTER = 'poster@example.test';

    private string $databasePath = '';

    private string $tempNzbPath = '';

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-late-collection-merge-test.sqlite';
        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }
        new PDO('sqlite:'.$this->databasePath);
        putenv('APP_ENV=testing');
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE='.$this->databasePath);

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempNzbPath = sys_get_temp_dir().'/nntmux-late-merge-'.uniqid('', true);
        mkdir($this->tempNzbPath, 0777, true);
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'nntmux_settings.path_to_nzbs' => $this->tempNzbPath,
            'nntmux.merge_late_collections' => true,
        ]);
        DB::purge();
        DB::reconnect();
        DB::connection()->getPdo()->sqliteCreateFunction('UNIX_TIMESTAMP', static fn (?string $value): int => strtotime((string) $value));

        $this->createSchema();
        NzbCreationCandidateQuery::flushCapabilityCache();
    }

    protected function tearDown(): void
    {
        NzbCreationCandidateQuery::flushCapabilityCache();
        exec('rm -rf '.escapeshellarg($this->tempNzbPath));
        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_late_parts_complete_the_existing_nzb_instead_of_a_second_release(): void
    {
        $nzbPath = $this->releaseWithPartialNzb();

        // Part 2 of file 1 and all of file 2 arrive after the release was written.
        $this->insertCollection(101, null, CollectionFileCheckStatus::Sized, '2026-11-09 10:00:00');
        $this->insertBinary(1002, 101, 'Example.Release.part01.rar yEnc', 2, [[2, 'f1p2', 200]]);
        $this->insertBinary(1003, 101, 'Example.Release.part02.rar yEnc', 2, [[2, 'f2p2', 400], [1, 'f2p1', 300]]);

        $totals = $this->merger()->mergeSized();

        $this->assertSame(['merged' => 1, 'segments' => 3], $totals);
        $xml = (string) unzipGzipFile($nzbPath);
        foreach (['f1p1', 'f1p2', 'f2p1', 'f2p2'] as $id) {
            $this->assertStringContainsString($id.'@example.test', $xml);
        }
        $this->assertLessThan(strpos($xml, 'f1p2@'), strpos($xml, 'f1p1@'));
        $this->assertLessThan(strpos($xml, 'f2p2@'), strpos($xml, 'f2p1@'));
        $this->assertSame(2, substr_count($xml, '<file '));

        $release = DB::table('releases')->find(1);
        $this->assertSame(1000, (int) $release->size);
        $this->assertSame(2, (int) $release->totalpart);
        $this->assertSame(100.0, (float) $release->completion);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('parts')->count());
    }

    public function test_already_known_segments_are_not_duplicated(): void
    {
        $nzbPath = $this->releaseWithPartialNzb();
        $before = (string) unzipGzipFile($nzbPath);

        $this->insertCollection(101, null, CollectionFileCheckStatus::Sized, '2026-11-09 10:00:00');
        $this->insertBinary(1002, 101, 'Example.Release.part01.rar yEnc', 2, [[1, 'f1p1', 100]]);

        $this->assertSame(['merged' => 1, 'segments' => 0], $this->merger()->mergeSized());
        $this->assertSame($before, (string) unzipGzipFile($nzbPath));
        $this->assertSame(100, (int) DB::table('releases')->value('size'));
        $this->assertSame(0, DB::table('collections')->count());
    }

    public function test_a_different_poster_is_left_for_normal_release_creation(): void
    {
        $this->releaseWithPartialNzb();

        $this->insertCollection(101, null, CollectionFileCheckStatus::Sized, '2026-11-09 10:00:00', 'someone@else.test');
        $this->insertBinary(1002, 101, 'Example.Release.part01.rar yEnc', 2, [[2, 'f1p2', 200]]);

        $this->assertSame(['merged' => 0, 'segments' => 0], $this->merger()->mergeSized());
        $this->assertSame(CollectionFileCheckStatus::Sized->value, (int) DB::table('collections')->where('id', 101)->value('filecheck'));
    }

    public function test_a_reupload_weeks_later_is_left_for_normal_release_creation(): void
    {
        $this->releaseWithPartialNzb();

        $this->insertCollection(101, null, CollectionFileCheckStatus::Sized, '2026-12-20 10:00:00');
        $this->insertBinary(1002, 101, 'Example.Release.part01.rar yEnc', 2, [[2, 'f1p2', 200]]);

        $this->assertSame(['merged' => 0, 'segments' => 0], $this->merger()->mergeSized());
        $this->assertSame(1, DB::table('collections')->count());
    }

    public function test_nothing_is_remembered_or_merged_when_disabled(): void
    {
        config(['nntmux.merge_late_collections' => false]);
        $merger = $this->merger();

        $merger->remember(1, self::HASH);

        $this->assertFalse($merger->enabled());
        $this->assertSame(0, DB::table('release_collection_hashes')->count());
        $this->assertSame(['merged' => 0, 'segments' => 0], $merger->mergeSized());
    }

    public function test_index_command_rebuilds_the_ingest_hash_from_the_nzb(): void
    {
        $subject = 'Example Release [01/02] - "Example.Release.part01.rar" yEnc';
        DB::table('releases')->insert([
            'id' => 2, 'guid' => str_repeat('c', 36), 'leftguid' => 'c', 'name' => 'Example.Release', 'searchname' => 'Example.Release',
            'groups_id' => 1, 'categories_id' => 1, 'fromname' => self::POSTER, 'postdate' => '2026-11-08 10:00:00',
            'nzbstatus' => NzbService::NZB_NONE, 'adddate' => now(),
        ]);
        $this->insertCollection(200, 2, CollectionFileCheckStatus::Inserted, '2026-11-08 10:00:00');
        $this->insertBinary(2000, 200, $subject, 3, [[1, 'x1', 100]]);
        $release = Release::query()->findOrFail(2);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);
        $this->assertTrue(app(NzbService::class)->createNzbForRelease($release)->success);

        // Ingest hashes the cleaned header subject plus its declared file count.
        $this->app->instance(CollectionsCleaningService::class, new class extends CollectionsCleaningService
        {
            public function __construct() {}

            public function collectionsCleaner(string $subject, string $groupName = ''): array
            {
                return ['id' => 0, 'name' => $groupName.'|'.$subject];
            }
        });

        $this->artisan('releases:index-collection-hashes')->assertSuccessful();

        $this->assertSame(sha1('alt.test|'.$subject.'2', true), DB::table('release_collection_hashes')->where('releases_id', 2)->value('collectionhash'));
    }

    /**
     * Release 1 holds only part 1 of file 1 out of two two-part files.
     */
    private function releaseWithPartialNzb(): string
    {
        DB::table('releases')->insert([
            'id' => 1, 'guid' => str_repeat('b', 36), 'leftguid' => 'b', 'name' => 'Example.Release', 'searchname' => 'Example.Release',
            'groups_id' => 1, 'categories_id' => 1, 'fromname' => self::POSTER, 'postdate' => '2026-11-08 10:00:00',
            'size' => 100, 'totalpart' => 1, 'completion' => 0, 'nzbstatus' => NzbService::NZB_NONE,
        ]);
        $this->insertCollection(100, 1, CollectionFileCheckStatus::Inserted, '2026-11-08 10:00:00');
        $this->insertBinary(1000, 100, 'Example.Release.part01.rar yEnc', 2, [[1, 'f1p1', 100]]);

        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);
        $result = app(NzbService::class)->createNzbForRelease($release);
        $this->assertTrue($result->success, $result->reason);
        $this->merger()->remember(1, self::HASH);

        return (string) $result->path;
    }

    private function insertCollection(int $id, ?int $releaseId, CollectionFileCheckStatus $status, string $date, string $poster = self::POSTER): void
    {
        DB::table('collections')->insert([
            'id' => $id, 'releases_id' => $releaseId, 'collectionhash' => self::HASH, 'fromname' => $poster,
            'date' => $date, 'xref' => 'alt.test:1', 'groups_id' => 1, 'filecheck' => $status->value, 'filesize' => 1,
        ]);
        DB::table('collection_groups')->insert(['collections_id' => $id, 'group_name' => 'alt.test']);
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}>  $parts  partnumber, message-id prefix, bytes
     */
    private function insertBinary(int $id, int $collectionId, string $name, int $totalParts, array $parts): void
    {
        DB::table('binaries')->insert(['id' => $id, 'collections_id' => $collectionId, 'name' => $name, 'totalparts' => $totalParts]);
        foreach ($parts as [$number, $prefix, $bytes]) {
            DB::table('parts')->insert(['binaries_id' => $id, 'messageid' => "<{$prefix}@example.test>", 'partnumber' => $number, 'size' => $bytes]);
        }
    }

    private function merger(): LateCollectionMerger
    {
        return new LateCollectionMerger(app(NzbService::class), app(CollectionCleanupService::class));
    }

    private function createSchema(): void
    {
        DB::statement('CREATE TABLE root_categories (id INTEGER PRIMARY KEY, title VARCHAR(255), status INTEGER DEFAULT 1, disablepreview INTEGER DEFAULT 0)');
        DB::statement('CREATE TABLE categories (id INTEGER PRIMARY KEY, title VARCHAR(255), root_categories_id INTEGER NULL)');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY, guid VARCHAR(64), leftguid VARCHAR(1), name VARCHAR(255), searchname VARCHAR(255),
            groups_id INTEGER, categories_id INTEGER, fromname VARCHAR(255) NULL, postdate DATETIME NULL,
            size BIGINT DEFAULT 0, totalpart INTEGER DEFAULT 0, completion REAL DEFAULT 0, nzbstatus INTEGER, adddate DATETIME NULL,
            nzb_creation_claimed_at DATETIME NULL, nzb_creation_claim_token VARCHAR(64) NULL
        )');
        DB::statement('CREATE TABLE release_nzb_creation_failures (releases_id INTEGER PRIMARY KEY, attempts INTEGER DEFAULT 0, last_error TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255))');
        DB::statement('CREATE TABLE releases_groups (releases_id INTEGER, groups_id INTEGER, PRIMARY KEY (releases_id, groups_id))');
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY, releases_id INTEGER NULL, collectionhash BLOB, fromname VARCHAR(255) NULL, date DATETIME NULL,
            xref TEXT NULL, groups_id INTEGER NULL, filecheck INTEGER DEFAULT 0, filesize BIGINT DEFAULT 0
        )');
        DB::statement('CREATE TABLE collection_groups (collections_id INTEGER, group_name VARCHAR(255))');
        DB::statement('CREATE TABLE binaries (id INTEGER PRIMARY KEY, collections_id INTEGER, name VARCHAR(255) NULL, totalparts INTEGER NULL)');
        DB::statement('CREATE TABLE parts (binaries_id INTEGER, messageid VARCHAR(255) NULL, partnumber INTEGER NULL, size INTEGER NULL)');
        DB::statement('CREATE TABLE release_collection_hashes (collectionhash BLOB PRIMARY KEY, releases_id INTEGER, created_at DATETIME NULL)');
        DB::table('root_categories')->insert(['id' => 1, 'title' => 'Other']);
        DB::table('categories')->insert(['id' => 1, 'title' => 'Misc', 'root_categories_id' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
    }
}
