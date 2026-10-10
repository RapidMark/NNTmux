<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

final readonly class ArchiveCandidate
{
    /**
     * @param  list<string>  $messageIds
     * @param  list<string>  $tailMessageIds
     */
    public function __construct(
        public string $title,
        public array $messageIds,
        public bool $likelyFirstVolume,
        public int $sourceIndex,
        public array $tailMessageIds = [],
        public bool $hasFirstSegment = true,
    ) {}

    /**
     * Name shared by every volume or piece of the file this candidate belongs to.
     */
    public function volumeSet(): string
    {
        $name = preg_match('/"([^"\r\n]+)"/', $this->title, $quoted) === 1 ? $quoted[1] : $this->title;

        return strtolower((string) preg_replace(
            '/\.(?:part\d+\.rar|rar|r\d{2,3}|7z(?:\.\d{3})?|\d{3}|z\d{2}|zipx?)$/i',
            '',
            trim($name),
        ));
    }
}
