<?php

namespace simplygoodwork\craftportage\export;

use benf\neo\elements\Block;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\Section;
use InvalidArgumentException;
use simplygoodwork\craftportage\fields\FieldAdapterRegistry;
use simplygoodwork\craftportage\fields\RequiresOwner;
use simplygoodwork\craftportage\references\ElementTypes;
use simplygoodwork\craftportage\references\NaturalKey;

/**
 * State for one Export: turns elements into portable data and collects the Schema references and References they touch.
 *
 * Field adapters receive it so they can export Owned content and record References.
 */
final class ExportContext
{
    /**
     * @var array<string, array<string, true>> kind => UID set
     */
    private array $schema = [
        'sites' => [],
        'sections' => [],
        'entryTypes' => [],
        'neoBlockTypes' => [],
        'hyperLinkTypes' => [],
        'fields' => [],
        'volumes' => [],
    ];

    /**
     * @var array<string, array<string, mixed>> UID => Reference
     */
    private array $references = [];

    /**
     * @var list<int>|null IDs of the sites being exported, which limit the sites Owned content is exported in
     */
    private ?array $siteIds = null;

    private ?ElementInterface $element = null;

    /**
     * @param array<string, true> $rootUids UIDs of the Root elements being exported, which are never Referenced as a parent
     * @param list<int>|null $allowedSiteIds the only sites content is exported from, or null for every site
     */
    public function __construct(
        private readonly FieldAdapterRegistry $adapters,
        private readonly array $rootUids = [],
        private readonly ?array $allowedSiteIds = null,
    ) {
    }

    /**
     * The UID a Root entry is exported under: a draft's content stands for the entry it's a draft of, so it carries that
     * entry's UID, and an Import matches it to the entry it came from rather than to a draft that exists nowhere else.
     * An unpublished draft is its own entry.
     */
    public static function rootUid(Entry|Block $root): string
    {
        return $root instanceof Block ? (string)$root->uid : (string)$root->getCanonical(true)->uid;
    }

    /**
     * Returns whether a Root element is a Matrix or Neo block (an element that is Owned content of a field) rather than
     * a whole entry.
     */
    public static function isBlock(Entry|Block $root): bool
    {
        return $root instanceof Block || $root->fieldId !== null;
    }

    /**
     * Exports a Root element with its content for every site it exists in.
     *
     * @return array<string, mixed>
     */
    public function exportRoot(Entry $entry): array
    {
        $section = $entry->getSection();
        $this->schema['sections'][$section->uid] = true;

        return [
            'elementType' => ElementTypes::nameOf($entry),
            'uid' => self::rootUid($entry),
            'section' => $section->uid,
            'type' => $this->entryTypeUid($entry),
            'authors' => array_map(fn(User $author) => $this->addReference($author), $entry->getAuthors()),
            ...$this->structurePath($entry),
            ...$this->content($this->inEverySite($entry)),
        ];
    }

    /**
     * Exports a Matrix or Neo block as a Root element, to be pasted into a field of another environment. It carries the UID
     * of the field it was copied from, so a Neo block can only go back into the same field, and its content for every
     * site. A Neo block also carries its child blocks, the blocks nested under it, which are Owned content; levels are
     * relative to the shallowest of the Root elements, 1 being the top.
     *
     * @param int $baseLevel the level of the shallowest Neo Root element being exported
     * @return array<string, mixed>
     */
    public function exportBlockRoot(Entry|Block $block, int $baseLevel = 1): array
    {
        $field = Craft::$app->getFields()->getFieldById((int)$block->fieldId);
        if ($field === null) {
            throw new InvalidArgumentException('The block doesn\'t belong to a field.');
        }
        $this->schema['fields'][$field->uid] = true;

        $adjust = function(array $nested) use ($baseLevel): array {
            if (isset($nested['level'])) {
                $nested['level'] = max(1, $nested['level'] - $baseLevel + 1);
            }

            return $nested;
        };

        return [
            'elementType' => $block instanceof Block ? ElementTypes::NEO_BLOCK : (string)ElementTypes::nameOf($block),
            'field' => $field->uid,
            ...$adjust($this->exportNested($block)),
            ...($block instanceof Block ? ['children' => array_map(fn(Block $child) => $adjust($this->exportNested($child)), self::childBlocks($block))] : []),
        ];
    }

    /**
     * Returns the blocks nested under a Neo block, in order: the blocks after it in its field that are at a deeper level,
     * up to the next block that isn't.
     *
     * @return list<Block>
     */
    public static function childBlocks(Block $block): array
    {
        $owner = $block->getOwner();
        $field = Craft::$app->getFields()->getFieldById((int)$block->fieldId);
        $value = $owner && $field ? $owner->getFieldValue($field->handle) : null;
        $siblings = $value instanceof ElementQueryInterface ? (clone $value)->status(null)->all() : [];

        $children = [];
        $found = false;
        foreach ($siblings as $sibling) {
            if (!$sibling instanceof Block) {
                continue;
            }
            if ($found) {
                if ((int)$sibling->level <= (int)$block->level) {
                    break;
                }
                $children[] = $sibling;
            } elseif ($sibling->id === $block->id) {
                $found = true;
            }
        }

        return $children;
    }

    /**
     * A saved Structure entry's place in its structure: the slugs of its ancestors, which are part of its Natural key
     * on Import (Overwrite finds the entry by section, slug and this path), and the UID of its parent, which the Import
     * places it under. The parent is a Reference, unless it's a Root element of the same Payload. An entry at the top
     * level has no parent.
     *
     * @return array{path?: list<string>, parent?: string|null}
     */
    private function structurePath(Entry $entry): array
    {
        if ($entry->id === null || $entry->getSection()?->type !== Section::TYPE_STRUCTURE) {
            return [];
        }

        $path = NaturalKey::of($entry)['path'] ?? [];
        $parent = $entry->getParent();
        $parentUid = $parent && !isset($this->rootUids[$parent->uid]) ? $this->addReference($parent) : $parent?->uid;

        return [
            'path' => is_array($path) ? array_values($path) : [],
            'parent' => $parentUid,
        ];
    }

    /**
     * Exports a nested entry or Neo block (Owned content) with its content for every site its owner's value is being
     * exported in. A Neo block also carries its level in the field's hierarchy, 1 being the top.
     *
     * @return array<string, mixed>
     */
    public function exportNested(Entry|Block $nested): array
    {
        return [
            'uid' => $nested->uid,
            'type' => $nested instanceof Block ? $this->blockTypeUid($nested) : $this->entryTypeUid($nested),
            'enabled' => $nested->enabled,
            ...($nested instanceof Block ? ['level' => (int)$nested->level] : []),
            ...$this->content($this->inEverySite($nested)),
        ];
    }

    /**
     * Records a related, not owned, element as a Reference and returns its UID (ADR-0002).
     *
     * An asset Reference also carries the asset's alt text, focal point and custom field values (the deliberate
     * exception in ADR-0002), so an asset fetched during Import keeps its metadata. Its `url` is the public URL its
     * file is fetched from, or null when its volume has none.
     */
    public function addReference(ElementInterface $element): string
    {
        $elementType = ElementTypes::nameOf($element);
        if ($elementType === null) {
            throw new InvalidArgumentException('Unsupported element type for a Reference: ' . $element::class);
        }
        if (isset($this->references[$element->uid])) {
            return $element->uid;
        }

        $this->references[$element->uid] = [
            'elementType' => $elementType,
            'uid' => $element->uid,
            // Users have no title, so they're named by their full name or username.
            'title' => $element->title ?? ((string)$element ?: null),
            'url' => $element->getUrl(),
            'naturalKey' => NaturalKey::of($element),
        ];
        if ($element instanceof Asset) {
            // After the Reference is recorded, so an asset whose fields relate back to it doesn't loop.
            $this->references[$element->uid]['asset'] = $this->assetMetadata($element);
        }

        return $element->uid;
    }

    /**
     * Records a Hyper link type, identified by its field layout's UID, as a Schema reference and returns the UID.
     */
    public function recordLinkType(string $uid): string
    {
        $this->schema['hyperLinkTypes'][$uid] = true;

        return $uid;
    }

    /**
     * Exports the custom field values of an element that isn't exported as content of its own (a Hyper link), keyed by
     * field UID. A field that holds Owned content has no element to own it, so it's exported as null for the Import to
     * warn about.
     *
     * @return array<string, mixed>
     */
    public function exportFields(ElementInterface $element): array
    {
        return $this->fields($element, translatable: null, ownerless: true);
    }

    /**
     * Records a site named by an Embedded reference as a Schema reference and returns its UID, or null if there's no
     * site with that ID.
     */
    public function siteUid(int $siteId): ?string
    {
        $uid = Craft::$app->getSites()->getSiteById($siteId, true)?->uid;
        if ($uid !== null) {
            $this->schema['sites'][$uid] = true;
        }

        return $uid;
    }

    /**
     * @return array{alt: string|null, focalPoint: array{x: float, y: float}|null, fields: array<string, mixed>}
     */
    private function assetMetadata(Asset $asset): array
    {
        $this->schema['volumes'][$asset->getVolume()->uid] = true;
        $focalPoint = $asset->getHasFocalPoint() ? $asset->getFocalPoint() : null;

        return [
            'alt' => $asset->alt,
            'focalPoint' => is_array($focalPoint) ? $focalPoint : null,
            'fields' => $this->fields($asset, translatable: null),
        ];
    }

    /**
     * @return array<string, array<string, mixed>> UID => Reference
     */
    public function references(): array
    {
        return $this->references;
    }

    /**
     * @return array<string, list<string>>
     */
    public function schema(): array
    {
        return array_map(fn(array $uids) => array_keys($uids), $this->schema);
    }

    private function entryTypeUid(Entry $entry): string
    {
        $uid = $entry->getType()->uid;
        $this->schema['entryTypes'][$uid] = true;

        return $uid;
    }

    private function blockTypeUid(Block $block): string
    {
        $uid = $block->getType()->uid;
        $this->schema['neoBlockTypes'][$uid] = true;

        return $uid;
    }

    /**
     * Returns the element in every site it exists in, limited to the sites being exported, in site order. The given
     * element stands for its own site, so unsaved changes on it are exported.
     *
     * @return non-empty-list<Entry|Block>
     */
    private function inEverySite(Entry|Block $element): array
    {
        $bySite = $element->id === null ? [] : $element::find()
            ->id($element->id)
            ->site('*')
            ->status(null)
            ->drafts(null)
            ->provisionalDrafts(null)
            ->indexBy('siteId')
            ->all();
        $bySite[$element->siteId] = $element;

        $instances = [];
        foreach (Craft::$app->getSites()->getAllSiteIds(true) as $siteId) {
            if (
                isset($bySite[$siteId]) &&
                ($this->siteIds === null || in_array($siteId, $this->siteIds, true)) &&
                ($this->allowedSiteIds === null || in_array($siteId, $this->allowedSiteIds, true))
            ) {
                $instances[] = $bySite[$siteId];
            }
        }

        return $instances ?: [$element];
    }

    /**
     * Exports an element's content: the values of non-translatable fields once, then the title, slug and
     * translatable field values for each site.
     *
     * @param non-empty-list<Entry|Block> $instances the element in each site being exported
     * @return array{fields: array<string, mixed>, sites: array<string, array<string, mixed>>}
     */
    private function content(array $instances): array
    {
        $outerSiteIds = $this->siteIds;

        $this->siteIds = array_map(fn(Entry|Block $instance) => (int)$instance->siteId, $instances);
        $fields = $this->fields($instances[0], translatable: false);

        $sites = [];
        foreach ($instances as $instance) {
            $site = $instance->getSite();
            $this->schema['sites'][$site->uid] = true;
            $this->siteIds = [(int)$instance->siteId];
            $sites[$site->uid] = [
                'title' => $instance->title,
                'slug' => $instance->slug,
                'fields' => $this->fields($instance, translatable: true),
            ];
        }

        $this->siteIds = $outerSiteIds;

        return ['fields' => $fields, 'sites' => $sites];
    }

    /**
     * Returns the element whose field value is being exported, for an adapter that needs it to serialize the value, or
     * null outside an adapter's `export()`.
     */
    public function element(): ?ElementInterface
    {
        return $this->element;
    }

    /**
     * @param bool|null $translatable whether to export the translatable fields or the others, or null for all of them
     * @param bool $ownerless whether the element is no one's owner, so fields that hold Owned content can't be exported
     * @return array<string, mixed> field UID => portable value
     */
    private function fields(ElementInterface $element, ?bool $translatable, bool $ownerless = false): array
    {
        $fields = [];

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $adapter = $this->adapters->adapterFor($field);
            if (!$adapter || ($translatable !== null && $field->getIsTranslatable($element) !== $translatable)) {
                continue;
            }
            $this->schema['fields'][$field->uid] = true;
            if ($ownerless && $adapter instanceof RequiresOwner) {
                $fields[$field->uid] = null;
                continue;
            }
            $outer = $this->element;
            $this->element = $element;
            try {
                $fields[$field->uid] = $adapter->export($field, $element->getFieldValue($field->handle), $this);
            } finally {
                $this->element = $outer;
            }
        }

        return $fields;
    }
}
