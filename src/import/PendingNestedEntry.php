<?php

namespace simplygoodwork\craftportage\import;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use RuntimeException;

/**
 * A CKEditor nested entry that has no owner to belong to until the Importer saves the element holding the field.
 *
 * Planning writes a token in the field's HTML where the nested entry goes. The Importer replaces the token with the
 * entry's embedded marker once it has saved the nested entry, owned by the element that was just saved.
 *
 * The nested entry has the content of every site its owner is imported in: the first save carries the content of the
 * owner's site, and Craft copies it to the entry's other sites, where the owner's save for that site applies its own.
 */
final class PendingNestedEntry
{
    private const TOKEN_PREFIX = '{portage-nested:';
    private const TOKEN_PATTERN = '/\{portage-nested:([0-9a-f-]{36})\}/';

    /**
     * @var array<int, array{enabled: bool, title?: string, slug?: string, fields: array<string, mixed>}> the entry's
     * content by site ID, as `ImportContext::nestedEntryData()` returns it
     */
    private array $siteData = [];

    /**
     * @param Entry $entry the unsaved nested entry
     * @param array{enabled: bool, title?: string, slug?: string, fields: array<string, mixed>} $data the content of
     * the first site it was planned for, used for any site it has no content for
     */
    public function __construct(public readonly Entry $entry, private readonly array $data)
    {
    }

    /**
     * @param array{enabled: bool, title?: string, slug?: string, fields: array<string, mixed>} $data
     */
    public function addSite(int $siteId, array $data): void
    {
        $this->siteData[$siteId] ??= $data;
    }

    public static function token(string $uid): string
    {
        return self::TOKEN_PREFIX . $uid . '}';
    }

    public static function hasTokens(string $text): bool
    {
        return str_contains($text, self::TOKEN_PREFIX);
    }

    /**
     * Returns the HTML that embeds an entry, naming it by an attribute: `data-entry-id` in a field's HTML, or
     * `data-entry-uid` in a Payload.
     */
    public static function marker(string $attribute, string|int $value): string
    {
        return sprintf('<craft-entry data-%s="%s">&nbsp;</craft-entry>', $attribute, $value);
    }

    /**
     * Saves the entry as Owned content of the owner, with the content for the owner's site, and returns the marker
     * that embeds it in the owner's field. An entry an earlier site's save already created is restored if Craft trashed
     * it, and given the owner's site's content instead.
     *
     * @param int $sortOrder the entry's place among the owner's nested entries in the field
     */
    public function save(ElementInterface $owner, int $sortOrder): string
    {
        $entry = $this->entry;
        if ($entry->id === null) {
            $entry->siteId = $owner->siteId;
            $entry->setPrimaryOwner($owner);
            $entry->setOwner($owner);
            $entry->setSortOrder($sortOrder);
            $this->saveWithContent($entry, $owner->siteId);
        } else {
            // Craft trashes the nested entries of an owner's site when it saves the owner with a token where their
            // markers should be, so a later site's entry may need restoring.
            $existing = Entry::find()->id($entry->id)->siteId($owner->siteId)->status(null)->trashed(null)->one();
            if ($existing?->trashed && !Craft::$app->getElements()->restoreElement($existing)) {
                throw new RuntimeException('Craft couldn\'t restore a nested entry: ' . implode(' ', $existing->getFirstErrors()));
            }
            if ($existing && isset($this->siteData[$owner->siteId])) {
                $this->saveWithContent($existing, $owner->siteId);
            }
        }

        return self::marker('entry-id', (int)$entry->id);
    }

    /**
     * Returns an unsaved copy of the entry with its first site's content, which a plan can inspect without saving.
     */
    public function contentEntry(): Entry
    {
        $copy = new Entry(['typeId' => $this->entry->typeId, 'fieldId' => $this->entry->fieldId]);
        $this->applyContent($copy, $this->data);

        return $copy;
    }

    /**
     * @param array{enabled: bool, title?: string, slug?: string, fields: array<string, mixed>} $data
     */
    private function applyContent(Entry $entry, array $data): void
    {
        $entry->enabled = $data['enabled'];
        if (isset($data['title'])) {
            $entry->title = $data['title'];
        }
        if (isset($data['slug'])) {
            $entry->slug = $data['slug'];
        }
        $entry->setFieldValues($data['fields']);
    }

    private function saveWithContent(Entry $entry, int $siteId): void
    {
        $this->applyContent($entry, $this->siteData[$siteId] ?? $this->data);
        $entry->setScenario(Entry::SCENARIO_ESSENTIALS);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException('Craft couldn\'t save a nested entry: ' . implode(' ', $entry->getFirstErrors()));
        }
    }

    /**
     * Replaces the tokens in a text with markers, saving the nested entries they stand for. A token with no pending
     * entry is removed.
     *
     * @param array<string, PendingNestedEntry> $pending by the entry's UID
     */
    public static function renderIn(string $text, array $pending, ElementInterface $owner): string
    {
        $sortOrder = 0;

        return (string)preg_replace_callback(
            self::TOKEN_PATTERN,
            fn(array $match) => isset($pending[$match[1]]) ? $pending[$match[1]]->save($owner, ++$sortOrder) : '',
            $text,
        );
    }
}
