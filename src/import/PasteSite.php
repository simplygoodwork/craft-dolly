<?php

namespace simplygoodwork\craftdolly\import;

use craft\models\Site;

/**
 * The blocks a pasted Root element adds to the draft, for the draft's site.
 */
final class PasteSite
{
    /**
     * @param array<string, mixed> $fragment the Matrix or Neo field value (`['entries'|'blocks' => […], 'sortOrder' => […]]`, keyed
     * by UID) that holds only the Root element's blocks
     */
    public function __construct(
        public readonly Site $site,
        public readonly array $fragment,
    ) {
    }

    /**
     * The number of blocks, with the blocks nested under them.
     */
    public function blockCount(): int
    {
        return count((array)($this->fragment['sortOrder'] ?? []));
    }
}
