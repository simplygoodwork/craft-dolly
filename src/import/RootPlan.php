<?php

namespace simplygoodwork\craftportage\import;

use craft\elements\Entry;

/**
 * What an Import will do with one Root element: the unsaved entry to create, or why the Root element is blocked,
 * plus everything that will be skipped or imported imperfectly.
 */
final class RootPlan
{
    /**
     * @var list<SitePlan> the entry's content for each of its other sites, saved after the entry
     */
    public array $sites = [];

    /**
     * @var string|null why the Root element can't be imported, or null if it can
     */
    public ?string $blockedBy = null;

    /**
     * @var Entry|null the entry the Import will save, fully populated but unsaved
     */
    public ?Entry $entry = null;

    /**
     * @var list<string> things that will be skipped or changed without blocking the Root element
     */
    public array $warnings = [];

    /**
     * @var list<array{uid: string, elementType: string|null, title: string|null, field: string}>
     */
    public array $unresolvedReferences = [];

    /**
     * @var list<string> names of required fields that will be left empty
     */
    public array $incompleteFields = [];

    /**
     * @var array<string, ReferencePlan> References the Root element relates to that will be Stubbed, by UID
     */
    public array $stubs = [];

    public function __construct(
        public readonly ?string $sourceUid,
        public readonly ?string $title = null,
    ) {
    }

    public function block(string $reason): void
    {
        $this->blockedBy = $reason;
        $this->entry = null;
        $this->sites = [];
    }

    public function isBlocked(): bool
    {
        return $this->blockedBy !== null;
    }

    /**
     * Adds a warning, once.
     */
    public function warn(string $warning): void
    {
        if (!in_array($warning, $this->warnings, true)) {
            $this->warnings[] = $warning;
        }
    }

    /**
     * Adds an Unresolved reference that will be skipped, once for each field.
     */
    public function addUnresolvedReference(string $uid, ?string $elementType, ?string $title, string $field): void
    {
        foreach ($this->unresolvedReferences as $reference) {
            if ($reference['uid'] === $uid && $reference['field'] === $field) {
                return;
            }
        }

        $this->unresolvedReferences[] = [
            'uid' => $uid,
            'elementType' => $elementType,
            'title' => $title,
            'field' => $field,
        ];
    }
}
