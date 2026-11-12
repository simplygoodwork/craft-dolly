<?php

namespace simplygoodwork\craftportage\export;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\Section;
use InvalidArgumentException;
use simplygoodwork\craftportage\fields\FieldAdapterRegistry;
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
        'fields' => [],
    ];

    /**
     * @var array<string, array<string, mixed>> UID => Reference
     */
    private array $references = [];

    /**
     * @var list<int>|null IDs of the sites being exported, which limit the sites Owned content is exported in
     */
    private ?array $siteIds = null;

    public function __construct(private readonly FieldAdapterRegistry $adapters)
    {
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
            'uid' => $entry->uid,
            'section' => $section->uid,
            'type' => $this->entryTypeUid($entry),
            'authors' => array_map(fn(User $author) => $this->addReference($author), $entry->getAuthors()),
            ...$this->structurePath($entry),
            ...$this->content($this->inEverySite($entry)),
        ];
    }

    /**
     * The slugs of a saved Structure entry's ancestors, which are part of its Natural key on Import (Overwrite finds
     * the entry by section, slug and this path).
     *
     * @return array{path?: list<string>}
     */
    private function structurePath(Entry $entry): array
    {
        if ($entry->id === null || $entry->getSection()?->type !== Section::TYPE_STRUCTURE) {
            return [];
        }

        $path = NaturalKey::of($entry)['path'] ?? [];

        return ['path' => is_array($path) ? array_values($path) : []];
    }

    /**
     * Exports a nested entry (Owned content) with its content for every site its owner's value is being exported in.
     *
     * @return array<string, mixed>
     */
    public function exportNested(Entry $entry): array
    {
        return [
            'uid' => $entry->uid,
            'type' => $this->entryTypeUid($entry),
            'enabled' => $entry->enabled,
            ...$this->content($this->inEverySite($entry)),
        ];
    }

    /**
     * Records a related, not owned, element as a Reference and returns its UID (ADR-0002).
     */
    public function addReference(ElementInterface $element): string
    {
        $elementType = ElementTypes::nameOf($element);
        if ($elementType === null) {
            throw new InvalidArgumentException('Unsupported element type for a Reference: ' . $element::class);
        }

        $this->references[$element->uid] ??= [
            'elementType' => $elementType,
            'uid' => $element->uid,
            // Users have no title, so they're named by their full name or username.
            'title' => $element->title ?? ((string)$element ?: null),
            'url' => $element->getUrl(),
            'naturalKey' => NaturalKey::of($element),
        ];

        return $element->uid;
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

    /**
     * Returns the element in every site it exists in, limited to the sites being exported, in site order. The given
     * element stands for its own site, so unsaved changes on it are exported.
     *
     * @return non-empty-list<Entry>
     */
    private function inEverySite(Entry $entry): array
    {
        $bySite = $entry->id === null ? [] : Entry::find()
            ->id($entry->id)
            ->site('*')
            ->status(null)
            ->drafts(null)
            ->provisionalDrafts(null)
            ->indexBy('siteId')
            ->all();
        $bySite[$entry->siteId] = $entry;

        $instances = [];
        foreach (Craft::$app->getSites()->getAllSiteIds(true) as $siteId) {
            if (isset($bySite[$siteId]) && ($this->siteIds === null || in_array($siteId, $this->siteIds, true))) {
                $instances[] = $bySite[$siteId];
            }
        }

        return $instances ?: [$entry];
    }

    /**
     * Exports an element's content: the values of non-translatable fields once, then the title, slug and
     * translatable field values for each site.
     *
     * @param non-empty-list<Entry> $instances the element in each site being exported
     * @return array{fields: array<string, mixed>, sites: array<string, array<string, mixed>>}
     */
    private function content(array $instances): array
    {
        $outerSiteIds = $this->siteIds;

        $this->siteIds = array_map(fn(Entry $instance) => (int)$instance->siteId, $instances);
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
     * @return array<string, mixed> field UID => portable value
     */
    private function fields(ElementInterface $element, bool $translatable): array
    {
        $fields = [];

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $adapter = $this->adapters->adapterFor($field);
            if (!$adapter || $field->getIsTranslatable($element) !== $translatable) {
                continue;
            }
            $this->schema['fields'][$field->uid] = true;
            $fields[$field->uid] = $adapter->export($field, $element->getFieldValue($field->handle), $this);
        }

        return $fields;
    }
}
