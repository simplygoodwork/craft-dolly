<?php

namespace simplygoodwork\craftdolly\import;

use craft\elements\Entry;

/**
 * Where an Import will put a new Structure entry: under an existing entry (matched, replacement or Stub), under a
 * Root element of the same Payload once it's imported, or at the top level. It always goes after its new siblings.
 */
final class Placement
{
    /**
     * @param Entry|null $parent the entry it goes under, or null
     * @param RootPlan|null $parentRoot the Root element of this Payload it goes under, or null
     * @param string|null $parentName names the parent Root element in messages
     * @param bool $moveToEnd whether the section places new entries first, so the Import must move it after its siblings
     */
    public function __construct(
        public readonly ?Entry $parent = null,
        public readonly ?RootPlan $parentRoot = null,
        public readonly ?string $parentName = null,
        public readonly bool $moveToEnd = false,
    ) {
    }

    /**
     * Returns the ID of the entry to place it under, or null for the top level.
     *
     * @param RootResult|null $parentRootResult the result of importing the parent Root element, when there is one
     */
    public function parentId(?RootResult $parentRootResult): ?int
    {
        if ($this->parentRoot) {
            return $parentRootResult?->status === RootResult::STATUS_CREATED ? $parentRootResult->elementId : null;
        }

        return $this->parent?->id;
    }
}
