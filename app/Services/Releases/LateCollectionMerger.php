<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\CollectionFileCheckStatus;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbService;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Adds headers that arrive after a post was released to that release's NZB.
 *
 * A collection that times out becomes a release with whatever parts were seen;
 * its collection rows are deleted once the NZB is written. Late parts of the same
 * post rebuild the same collection hash, and without this they turn into a second,
 * partial release. Each release remembers its collection hash, and a sized
 * collection with a remembered hash is merged into the release instead.
 */
final class LateCollectionMerger
{
    /** Posts more than this far apart are treated as different uploads sharing a name. */
    private const MAX_POSTDATE_GAP_SECONDS = 7 * 86400;

    private const BATCH = 500;

    private const NZB_XML_NS = 'http://www.newzbin.com/DTD/2003/nzb';

    private ?bool $tableExists = null;

    public function __construct(
        private readonly NzbService $nzb,
        private readonly CollectionCleanupService $collectionCleanupService,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('nntmux.merge_late_collections', false) && $this->hasTable();
    }

    /**
     * Remember which collection hash a new release was built from.
     */
    public function remember(int $releaseId, string $collectionHash): void
    {
        if (! $this->enabled() || $collectionHash === '') {
            return;
        }

        DB::table('release_collection_hashes')->upsert(
            [['collectionhash' => $collectionHash, 'releases_id' => $releaseId, 'created_at' => now()]],
            ['collectionhash'],
            ['releases_id', 'created_at']
        );
    }

    /**
     * SQL condition (on collection alias `c`) that is true for collections of an already released post.
     */
    public function releasedPostCondition(): string
    {
        return 'EXISTS (SELECT 1 FROM release_collection_hashes h WHERE h.collectionhash = c.collectionhash)';
    }

    /**
     * Merge sized collections whose post was already released.
     *
     * @return array{merged: int, segments: int}
     */
    public function mergeSized(?int $groupId = null): array
    {
        $totals = ['merged' => 0, 'segments' => 0];
        if (! $this->enabled()) {
            return $totals;
        }

        $query = DB::table('collections as c')
            ->join('release_collection_hashes as h', 'h.collectionhash', '=', 'c.collectionhash')
            ->join('releases as r', 'r.id', '=', 'h.releases_id')
            ->join('usenet_groups as g', 'g.id', '=', 'c.groups_id')
            ->where('c.filecheck', CollectionFileCheckStatus::Sized->value)
            ->whereNull('c.releases_id')
            ->where('r.nzbstatus', NzbService::NZB_ADDED)
            ->select([
                'c.id', 'c.fromname', 'c.date', 'g.name as groupname',
                'r.id as release_id', 'r.guid', 'r.fromname as release_fromname', 'r.postdate',
            ])
            ->orderBy('c.id')
            ->limit(self::BATCH);
        if ($groupId !== null) {
            $query->where('c.groups_id', $groupId);
        }

        foreach ($query->get() as $row) {
            if (! $this->samePost($row)) {
                continue;
            }
            $added = $this->mergeOne($row);
            if ($added !== null) {
                $totals['merged']++;
                $totals['segments'] += $added;
            }
        }

        return $totals;
    }

    /**
     * The hash ignores the poster and date, so guard against unrelated uploads with the same name.
     */
    private function samePost(object $row): bool
    {
        if (mb_strtolower(trim((string) $row->fromname)) !== mb_strtolower(trim((string) $row->release_fromname))) {
            return false;
        }
        $collectionTime = strtotime((string) $row->date);
        $releaseTime = strtotime((string) $row->postdate);

        return $collectionTime !== false && $releaseTime !== false
            && abs($collectionTime - $releaseTime) <= self::MAX_POSTDATE_GAP_SECONDS;
    }

    /**
     * @return int|null segments added, or null when the collection was left for normal processing
     */
    private function mergeOne(object $row): ?int
    {
        $collectionId = (int) $row->id;

        // Inserted without a release keeps it out of release creation and size cleanup while merging.
        $claimed = DB::table('collections')
            ->where('id', $collectionId)
            ->where('filecheck', CollectionFileCheckStatus::Sized->value)
            ->whereNull('releases_id')
            ->update(['filecheck' => CollectionFileCheckStatus::Inserted->value]);
        if ($claimed !== 1) {
            return null;
        }

        try {
            $result = $this->mergeIntoNzb($row);
        } catch (Throwable $e) {
            Log::warning('Late collection merge failed', [
                'collection_id' => $collectionId, 'release_id' => $row->release_id, 'error' => $e->getMessage(),
            ]);
            $result = null;
        }

        if ($result === null) {
            DB::table('collections')->where('id', $collectionId)
                ->update(['filecheck' => CollectionFileCheckStatus::Sized->value]);

            return null;
        }

        $this->updateRelease((int) $row->release_id, $result, $this->collectionGroupNames($collectionId, (string) $row->groupname));
        $this->collectionCleanupService->deleteCollectionsAndDescendants([$collectionId], 'Late merge cleanup');

        return $result['added_segments'];
    }

    /**
     * @return array{added_segments: int, added_bytes: int, files: int, completion: float}|null
     */
    private function mergeIntoNzb(object $row): ?array
    {
        $path = $this->nzb->nzbPath((string) $row->guid);
        if ($path === false || ! is_readable($path) || ! is_writable(dirname($path))) {
            return null;
        }

        $lock = fopen($path.'.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            return null;
        }

        try {
            $raw = unzipGzipFile($path);
            $document = new DOMDocument;
            $document->preserveWhiteSpace = false;
            $document->formatOutput = true;
            if (! is_string($raw) || ! @$document->loadXML($raw, LIBXML_NONET)) {
                return null;
            }
            $root = $document->documentElement;
            if ($root === null || $root->localName !== 'nzb') {
                return null;
            }

            [$filesBySubject, $knownIds] = $this->indexNzb($document);
            $groups = $this->collectionGroupNames((int) $row->id, (string) $row->groupname);
            $addedSegments = 0;
            $addedBytes = 0;
            $touched = [];

            foreach ($this->collectionParts((int) $row->id) as $part) {
                $messageId = trim((string) $part->messageid, " \t\n\r\0\x0B<>");
                if ($messageId === '' || isset($knownIds[$messageId])) {
                    continue;
                }
                $subject = rtrim((string) $part->binary_name).' (1/'.(int) $part->totalparts.')';
                $file = $filesBySubject[$subject] ??= $this->appendFile($document, $root, $row, $subject, $groups);

                $segment = $document->createElementNS($root->namespaceURI ?: self::NZB_XML_NS, 'segment', htmlspecialchars($messageId, ENT_XML1));
                $segment->setAttribute('bytes', (string) (int) $part->size);
                $segment->setAttribute('number', (string) (int) $part->partnumber);
                $this->segmentsOf($file)->appendChild($segment);

                $knownIds[$messageId] = true;
                $touched[spl_object_id($file)] = $file;
                $addedSegments++;
                $addedBytes += (int) $part->size;
            }

            foreach ($touched as $file) {
                $this->sortSegments($file);
            }

            if ($addedSegments > 0 && ! $this->writeNzb($path, $document)) {
                return null;
            }

            return [
                'added_segments' => $addedSegments,
                'added_bytes' => $addedBytes,
                'files' => count($filesBySubject),
                'completion' => $this->completion($document),
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($path.'.lock');
        }
    }

    /**
     * @return array{0: array<string, DOMElement>, 1: array<string, true>}
     */
    private function indexNzb(DOMDocument $document): array
    {
        $files = [];
        $ids = [];
        foreach ($document->getElementsByTagName('file') as $file) {
            $files[$file->getAttribute('subject')] = $file;
        }
        foreach ($document->getElementsByTagName('segment') as $segment) {
            $ids[trim($segment->textContent, " \t\n\r\0\x0B<>")] = true;
        }

        return [$files, $ids];
    }

    /**
     * @param  list<string>  $groups
     */
    private function appendFile(DOMDocument $document, DOMElement $root, object $row, string $subject, array $groups): DOMElement
    {
        $ns = $root->namespaceURI ?: self::NZB_XML_NS;
        $file = $document->createElementNS($ns, 'file');
        $file->setAttribute('poster', (string) $row->fromname);
        $file->setAttribute('date', (string) (strtotime((string) $row->date) ?: time()));
        $file->setAttribute('subject', $subject);
        $groupsElement = $document->createElementNS($ns, 'groups');
        foreach ($groups as $group) {
            $groupsElement->appendChild($document->createElementNS($ns, 'group', htmlspecialchars($group, ENT_XML1)));
        }
        $file->appendChild($groupsElement);
        $file->appendChild($document->createElementNS($ns, 'segments'));
        $root->appendChild($file);

        return $file;
    }

    private function segmentsOf(DOMElement $file): DOMElement
    {
        foreach ($file->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'segments') {
                return $child;
            }
        }
        $segments = $file->ownerDocument->createElementNS($file->namespaceURI ?: self::NZB_XML_NS, 'segments');
        $file->appendChild($segments);

        return $segments;
    }

    private function sortSegments(DOMElement $file): void
    {
        $segments = $this->segmentsOf($file);
        $children = [];
        foreach (iterator_to_array($segments->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $segments->removeChild($child);
            }
        }
        usort($children, static fn (DOMElement $a, DOMElement $b): int => (int) $a->getAttribute('number') <=> (int) $b->getAttribute('number'));
        foreach ($children as $child) {
            $segments->appendChild($child);
        }
    }

    /**
     * Same measure as NzbContentsService: segments present over segments the subjects declare.
     */
    private function completion(DOMDocument $document): float
    {
        $actual = 0;
        $expected = 0;
        foreach ($document->getElementsByTagName('file') as $file) {
            if (preg_match('/\((\d+)\/(\d+)\)\s*$/', $file->getAttribute('subject'), $match)) {
                $expected += (int) $match[2];
            }
            $actual += $this->segmentsOf($file)->getElementsByTagName('segment')->length;
        }

        return $expected > 0 ? min(100.0, $actual / $expected * 100) : 0.0;
    }

    private function writeNzb(string $path, DOMDocument $document): bool
    {
        $xml = $document->saveXML();
        if ($xml === false) {
            return false;
        }
        $encoded = str_ends_with($path, '.gz') ? gzencode($xml, 7) : $xml;
        $temporary = tempnam(dirname($path), '.merge-');
        if ($encoded === false || $temporary === false) {
            return false;
        }
        try {
            if (file_put_contents($temporary, $encoded) !== strlen($encoded)) {
                return false;
            }
            chmod($temporary, fileperms($path) & 0777);

            return rename($temporary, $path);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * @param  array{added_segments: int, added_bytes: int, files: int, completion: float}  $result
     * @param  list<string>  $groups
     */
    private function updateRelease(int $releaseId, array $result, array $groups): void
    {
        DB::table('releases')->where('id', $releaseId)->update([
            'size' => DB::raw('size + '.(int) $result['added_bytes']),
            'totalpart' => $result['files'],
            'completion' => $result['completion'],
        ]);

        if ($groups !== [] && Schema::hasTable('releases_groups')) {
            $groupIds = DB::table('usenet_groups')->whereIn('name', $groups)->pluck('id');
            DB::table('releases_groups')->insertOrIgnore(
                $groupIds->map(static fn ($id): array => ['releases_id' => $releaseId, 'groups_id' => (int) $id])->all()
            );
        }
    }

    /**
     * @return list<object>
     */
    private function collectionParts(int $collectionId): array
    {
        return DB::select(
            'SELECT b.name AS binary_name, b.totalparts, p.messageid, p.size, p.partnumber
             FROM binaries b INNER JOIN parts p ON p.binaries_id = b.id
             WHERE b.collections_id = ? ORDER BY b.name, b.id, p.partnumber',
            [$collectionId]
        );
    }

    /**
     * @return list<string>
     */
    private function collectionGroupNames(int $collectionId, string $fallback): array
    {
        $groups = Schema::hasTable('collection_groups')
            ? DB::table('collection_groups')->where('collections_id', $collectionId)->orderBy('group_name')->pluck('group_name')->map(static fn ($g): string => (string) $g)->all()
            : [];

        return $groups !== [] ? $groups : [$fallback];
    }

    private function hasTable(): bool
    {
        return $this->tableExists ??= Schema::hasTable('release_collection_hashes');
    }
}
