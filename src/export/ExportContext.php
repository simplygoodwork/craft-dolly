<?php

namespace simplygoodwork\craftportage\export;

use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\elements\User;
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

    public function __construct(private readonly FieldAdapterRegistry $adapters)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function exportRoot(Entry $entry): array
    {
        $site = $entry->getSite();
        $section = $entry->getSection();
        $this->schema['sites'][$site->uid] = true;
        $this->schema['sections'][$section->uid] = true;

        return [
            'elementType' => ElementTypes::nameOf($entry),
            'uid' => $entry->uid,
            'section' => $section->uid,
            'type' => $this->entryTypeUid($entry),
            'authors' => array_map(fn(User $author) => $this->addReference($author), $entry->getAuthors()),
            'sites' => [
                $site->uid => $this->content($entry),
            ],
        ];
    }

    /**
     * Exports a nested entry (Owned content) in its owner's site.
     *
     * @return array<string, mixed>
     */
    public function exportNested(Entry $entry): array
    {
        return [
            'uid' => $entry->uid,
            'type' => $this->entryTypeUid($entry),
            ...$this->content($entry),
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
     * @return array<string, mixed>
     */
    private function content(Entry $entry): array
    {
        return [
            'title' => $entry->title,
            'slug' => $entry->slug,
            'enabled' => $entry->enabled,
            'fields' => $this->fields($entry),
        ];
    }

    /**
     * @return array<string, mixed> field UID => portable value
     */
    private function fields(ElementInterface $element): array
    {
        $fields = [];

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $adapter = $this->adapters->adapterFor($field);
            if (!$adapter) {
                continue;
            }
            $this->schema['fields'][$field->uid] = true;
            $fields[$field->uid] = $adapter->export($field, $element->getFieldValue($field->handle), $this);
        }

        return $fields;
    }
}
