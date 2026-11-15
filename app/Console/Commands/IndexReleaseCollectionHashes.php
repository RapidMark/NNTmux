<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CollectionsCleaningService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\LateCollectionMerger;
use App\Support\SubjectFileCounter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('releases:index-collection-hashes
    {--days=30 : Releases added within this many days}
    {--incomplete : Only releases under 100% completion}
    {--limit=0 : Stop after this many releases (0 = no limit)}')]
#[Description('Record the collection hash of existing releases from their NZBs, so late headers merge into them')]
final class IndexReleaseCollectionHashes extends Command
{
    public function handle(NzbService $nzb, CollectionsCleaningService $cleaner, LateCollectionMerger $merger): int
    {
        if (! $merger->enabled()) {
            $this->error('Set NN_MERGE_LATE_COLLECTIONS=true and run migrations first.');

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $query = DB::table('releases as r')
            ->join('usenet_groups as g', 'g.id', '=', 'r.groups_id')
            ->leftJoin('release_collection_hashes as h', 'h.releases_id', '=', 'r.id')
            ->whereNull('h.releases_id')
            ->where('r.nzbstatus', NzbService::NZB_ADDED)
            ->where('r.adddate', '>=', now()->subDays(max(1, (int) $this->option('days'))))
            ->select(['r.id', 'r.guid', 'g.name as groupname']);
        if ($this->option('incomplete')) {
            $query->where('r.completion', '<', 100);
        }

        $indexed = 0;
        $skipped = 0;
        $query->orderBy('r.id')->chunk(1000, function ($releases) use ($nzb, $cleaner, $merger, $limit, &$indexed, &$skipped): bool {
            foreach ($releases as $release) {
                $hash = $this->hashFromNzb($nzb, $cleaner, (string) $release->guid, (string) $release->groupname);
                if ($hash === null) {
                    $skipped++;
                } else {
                    $merger->remember((int) $release->id, $hash);
                    $indexed++;
                }
                if ($limit > 0 && $indexed + $skipped >= $limit) {
                    return false;
                }
            }
            $this->line(sprintf('Indexed %s, skipped %s', number_format($indexed), number_format($skipped)));

            return true;
        });

        $this->info(sprintf('Done: %s indexed, %s without a usable NZB.', number_format($indexed), number_format($skipped)));

        return self::SUCCESS;
    }

    /**
     * Rebuild the ingest-time hash (cleaned subject + declared file count) from the NZB's first file subject.
     */
    private function hashFromNzb(NzbService $nzb, CollectionsCleaningService $cleaner, string $guid, string $groupName): ?string
    {
        $path = $nzb->nzbPath($guid);
        $raw = $path === false ? false : unzipGzipFile($path);
        if (! is_string($raw) || ! preg_match('/<file\b[^>]*\bsubject="([^"]*)"/', $raw, $match)) {
            return null;
        }

        // NzbService writes "<binary name> (1/<parts>)"; the binary name is the header subject before its part counter.
        $subject = preg_replace('/\s+\(1\/\d+\)$/', '', html_entity_decode($match[1], ENT_QUOTES | ENT_XML1));
        if ($subject === null || $subject === '') {
            return null;
        }
        $totalFiles = SubjectFileCounter::parse($subject)['totalfiles'] ?? 0;

        return sha1($cleaner->collectionsCleaner($subject, $groupName)['name'].$totalFiles, true);
    }
}
