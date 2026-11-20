<?php

namespace simplygoodwork\craftdolly\import;

use craft\models\Site;

/**
 * The content an Import will save for a Root entry in one of its sites other than the first, once the entry exists.
 */
final class SitePlan
{
    /**
     * @param array<string, mixed> $fieldValues `setFieldValues()` input, keyed by field handle
     */
    public function __construct(
        public readonly Site $site,
        public readonly ?string $title,
        public readonly ?string $slug,
        public readonly array $fieldValues,
    ) {
    }
}
