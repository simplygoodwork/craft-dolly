<?php

namespace simplygoodwork\craftportage\import;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\models\CategoryGroup;
use craft\models\Section;
use craft\models\TagGroup;
use simplygoodwork\craftportage\references\ElementTypes;

/**
 * Builds unsaved Stubs for Unresolved references: disabled, minimal elements in the Reference's original section or
 * group, populated only from the Reference's identifying information. The Importer saves them.
 *
 * Users are never Stubbed, and neither are the Payload's own Root elements: a Stub would duplicate the Root element.
 */
final class Stubs
{
    /**
     * @param array<string, true> $rootUids source UIDs of the Payload's Root elements, which are never Stubbed
     */
    public function __construct(
        private readonly UidClaims $uids,
        private readonly array $rootUids = [],
    ) {
    }

    /**
     * Returns whether a Reference can be Stubbed in this environment: its element type allows Stubs, its section
     * or group exists here, and it isn't one of the Payload's Root elements.
     */
    public function canStub(ReferencePlan $reference): bool
    {
        return !$this->isRoot($reference) && self::container($reference) !== null;
    }

    /**
     * Returns whether the Reference is one of the Payload's own Root elements.
     */
    public function isRoot(ReferencePlan $reference): bool
    {
        return isset($this->rootUids[$reference->uid]);
    }

    /**
     * Returns the unsaved Stub for a Reference, keeping the source UID when it's free (ADR-0003), or null if the
     * Reference can't be Stubbed.
     */
    public function build(ReferencePlan $reference): ?ElementInterface
    {
        if ($this->isRoot($reference)) {
            return null;
        }

        $container = self::container($reference);
        $key = $reference->reference['naturalKey'] ?? [];
        $slug = is_string($key['slug'] ?? null) && $key['slug'] !== '' ? $key['slug'] : null;

        $stub = match (true) {
            $container instanceof Section => $this->entry($container),
            $container instanceof CategoryGroup => new Category([
                'groupId' => $container->id,
                'siteId' => array_key_first($container->getSiteSettings()) ?? Craft::$app->getSites()->getPrimarySite()->id,
            ]),
            $container instanceof TagGroup => new Tag(['groupId' => $container->id]),
            default => null,
        };
        if (!$stub) {
            return null;
        }

        $stub->uid = $this->uids->claim($reference->uid);
        $stub->enabled = false;
        $stub->title = $reference->title;
        if ($slug !== null && !$stub instanceof Tag) {
            $stub->slug = $slug;
        }

        return $stub;
    }

    private function entry(Section $section): Entry
    {
        return new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'siteId' => array_key_first($section->getSiteSettings()) ?? Craft::$app->getSites()->getPrimarySite()->id,
        ]);
    }

    private static function sectionWithEntryTypes(mixed $uid): ?Section
    {
        $section = is_string($uid) ? Craft::$app->getEntries()->getSectionByUid($uid) : null;

        return $section && $section->getEntryTypes() ? $section : null;
    }

    /**
     * The section or group a Stub for the Reference would be created in, or null if there isn't one.
     */
    private static function container(ReferencePlan $reference): Section|CategoryGroup|TagGroup|null
    {
        $key = $reference->reference['naturalKey'] ?? null;
        if (!is_array($key)) {
            return null;
        }

        return match (ElementTypes::classFor($reference->elementType)) {
            Entry::class => self::sectionWithEntryTypes($key['section'] ?? null),
            Category::class => is_string($key['group'] ?? null) ? Craft::$app->getCategories()->getGroupByUid($key['group']) : null,
            Tag::class => is_string($key['group'] ?? null) ? Craft::$app->getTags()->getTagGroupByUid($key['group']) : null,
            default => null,
        };
    }
}
