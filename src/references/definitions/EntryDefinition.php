<?php

namespace simplygoodwork\craftportage\references\definitions;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\models\Section;
use simplygoodwork\craftportage\references\ElementTypeDefinition;
use simplygoodwork\craftportage\references\NaturalKey;

/**
 * Entries. Natural key: section UID + slug, plus the parent slug path in Structure sections. Limited to their section,
 * and Stubbed in it with its first entry type.
 */
final class EntryDefinition extends ElementTypeDefinition
{
    public function elementClass(): string
    {
        return Entry::class;
    }

    public function naturalKey(ElementInterface $element): ?array
    {
        $section = $element instanceof Entry ? $element->getSection() : null;
        if (!$section) {
            return null;
        }

        return [
            'section' => $section->uid,
            'slug' => $element->slug,
            'path' => $section->type === Section::TYPE_STRUCTURE ? NaturalKey::path($element) : [],
        ];
    }

    public function find(array $naturalKey): ?int
    {
        $section = self::section($naturalKey);
        $slug = $naturalKey['slug'] ?? null;
        if (!$section || !is_string($slug) || $slug === '') {
            return null;
        }

        $candidates = Entry::find()
            ->sectionId($section->id)
            ->slug(Db::escapeParam($slug))
            ->status(null)
            ->site('*')
            ->unique()
            ->all();

        return NaturalKey::onlyOnPath($candidates, $section->type === Section::TYPE_STRUCTURE ? $naturalKey['path'] ?? [] : null);
    }

    public function sourceFor(array $naturalKey): ?string
    {
        $section = self::section($naturalKey);

        return $section ? "section:$section->uid" : null;
    }

    public function isInSource(ElementInterface $element, string $source): bool
    {
        return $element instanceof Entry && $source === 'section:' . $element->getSection()?->uid;
    }

    public function sourceName(): string
    {
        return 'section';
    }

    public function canBeStubbed(): bool
    {
        return true;
    }

    public function stub(array $naturalKey, ?string $title): ?ElementInterface
    {
        $section = self::section($naturalKey);
        $entryType = $section?->getEntryTypes()[0] ?? null;
        if (!$section || !$entryType) {
            return null;
        }

        $stub = new Entry([
            'sectionId' => $section->id,
            'typeId' => $entryType->id,
            'siteId' => array_key_first($section->getSiteSettings()) ?? Craft::$app->getSites()->getPrimarySite()->id,
            'title' => $title,
        ]);
        $slug = $naturalKey['slug'] ?? null;
        if (is_string($slug) && $slug !== '') {
            $stub->slug = $slug;
        }

        return $stub;
    }

    public function whyStubForbidden(ElementInterface $stub): string
    {
        $section = $stub instanceof Entry ? $stub->getSection() : null;

        return $section
            ? "you don't have permission to create entries in the \"$section->name\" section"
            : parent::whyStubForbidden($stub);
    }

    /**
     * @param array<mixed> $naturalKey
     */
    private static function section(array $naturalKey): ?Section
    {
        $uid = $naturalKey['section'] ?? null;

        return is_string($uid) ? Craft::$app->getEntries()->getSectionByUid($uid) : null;
    }
}
