<?php

namespace simplygoodwork\craftportage\import;

use craft\elements\Entry;

/**
 * What an Import will do to the entry a Root element overwrites: create a draft on it and apply the Payload's content
 * for each site. The canonical entry is never changed.
 */
final class OverwritePlan
{
    /**
     * @param list<SitePlan> $sites the Payload's content for each site, the first site first
     */
    public function __construct(
        public readonly Entry $canonical,
        public readonly array $sites,
    ) {
    }
}
