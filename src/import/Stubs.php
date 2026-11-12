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
     * or group exists here, it isn't one of the Payload's Root elements, and the user running the Import may create
     * elements there.
     */
    public function canStub(ReferencePlan $reference): bool
    {
        $stub = $this->candidate($reference);

        return $stub !== null && $this->isPermitted($stub);
    }

    /**
     * Returns why the user running the Import may not create the Reference's Stub, or null if they may (or it can't be
     * Stubbed for another reason).
     */
    public function whyForbidden(ReferencePlan $reference): ?string
    {
        $stub = $this->candidate($reference);
        if ($stub === null || $this->isPermitted($stub)) {
            return null;
        }

        $container = self::container($reference);

        return match (true) {
            $container instanceof Section => "you don't have permission to create entries in the \"$container->name\" section",
            $container instanceof CategoryGroup => "you don't have permission to create categories in the \"$container->name\" group",
            $container instanceof TagGroup => "you don't have permission to create tags in the \"$container->name\" group",
            default => null,
        };
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
        $stub = $this->candidate($reference);
        if (!$stub || !$this->isPermitted($stub)) {
            return null;
        }

        $stub->uid = $this->uids->claim($reference->uid);

        return $stub;
    }

    /**
     * Returns the Reference's unsaved Stub without a UID, or null if it's a Root element or its section or group
     * doesn't exist here.
     */
    private function candidate(ReferencePlan $reference): ?ElementInterface
    {
        return $this->isRoot($reference) ? null : $this->unsaved($reference);
    }

    /**
     * Returns whether the user running the Import may save the Stub. With nobody logged in, Craft's permissions
     * aren't checked.
     */
    private function isPermitted(ElementInterface $stub): bool
    {
        $user = Craft::$app->getUser()->getIdentity();

        return !$user || Craft::$app->getElements()->canSave($stub, $user);
    }

    /**
     * Returns the Reference's Stub with its content but no UID, or null if its section or group doesn't exist here.
     */
    private function unsaved(ReferencePlan $reference): ?ElementInterface
    {
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
