<?php

namespace simplygoodwork\craftdolly\import;

use craft\db\Query;
use craft\db\Table;
use craft\helpers\StringHelper;

/**
 * Hands out source UIDs to elements created by one Import, when they're free in the Target environment (ADR-0003).
 */
final class UidClaims
{
    /**
     * @var array<string, true> UIDs already handed out in this Import, which may not be saved yet
     */
    private array $claimed = [];

    /**
     * Returns the source UID if it's unused, or null so that Craft generates a new one.
     *
     * Trashed elements still hold their UIDs, so they count as collisions.
     */
    public function claim(mixed $sourceUid): ?string
    {
        if (!is_string($sourceUid) || !StringHelper::isUUID($sourceUid) || isset($this->claimed[$sourceUid])) {
            return null;
        }

        $taken = (new Query())
            ->from(Table::ELEMENTS)
            ->where(['uid' => $sourceUid])
            ->exists();

        if ($taken) {
            return null;
        }

        $this->claimed[$sourceUid] = true;

        return $sourceUid;
    }
}
