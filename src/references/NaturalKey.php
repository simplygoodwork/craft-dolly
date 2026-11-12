<?php

namespace simplygoodwork\craftportage\references;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\helpers\Db;
use craft\models\Section;

/**
 * The Natural key of each element type: built from an element on Export, and matched in the Target environment on
 * Import when a Reference's UID doesn't match.
 *
 * - entry: section UID + slug, plus the parent slug path in Structure sections
 * - category: group UID + slug, plus the parent slug path
 * - tag: group UID + title
 * - user: email, then username
 *
 * A Natural key that matches more than one element matches none, so a Reference is never related to the wrong one.
 */
final class NaturalKey
{
    /**
     * @return array<string, mixed>|null
     */
    public static function of(ElementInterface $element): ?array
    {
        return match (true) {
            $element instanceof Entry => self::ofEntry($element),
            $element instanceof Category => self::ofCategory($element),
            $element instanceof Tag => ['group' => $element->getGroup()->uid, 'title' => $element->title],
            $element instanceof User => ['email' => $element->email, 'username' => $element->username],
            default => null,
        };
    }

    /**
     * Returns the ID of the one element matching a Natural key, or null if none or several match.
     *
     * @param class-string<ElementInterface> $class
     */
    public static function find(string $class, mixed $key): ?int
    {
        if (!is_array($key)) {
            return null;
        }

        return match ($class) {
            Entry::class => self::findEntry($key),
            Category::class => self::findCategory($key),
            Tag::class => self::findTag($key),
            User::class => self::findUser($key),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function ofEntry(Entry $entry): ?array
    {
        $section = $entry->getSection();
        if (!$section) {
            return null;
        }

        return [
            'section' => $section->uid,
            'slug' => $entry->slug,
            'path' => $section->type === Section::TYPE_STRUCTURE ? self::path($entry) : [],
        ];
    }

    /**
     * @param array<mixed> $key
     */
    private static function findEntry(array $key): ?int
    {
        $section = is_string($key['section'] ?? null) ? Craft::$app->getEntries()->getSectionByUid($key['section']) : null;
        if (!$section || !is_string($key['slug'] ?? null) || $key['slug'] === '') {
            return null;
        }

        $candidates = Entry::find()
            ->sectionId($section->id)
            ->slug(Db::escapeParam($key['slug']))
            ->status(null)
            ->site('*')
            ->unique()
            ->all();

        return self::onlyOnPath($candidates, $section->type === Section::TYPE_STRUCTURE ? $key['path'] ?? [] : null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function ofCategory(Category $category): array
    {
        return [
            'group' => $category->getGroup()->uid,
            'slug' => $category->slug,
            'path' => self::path($category),
        ];
    }

    /**
     * @param array<mixed> $key
     */
    private static function findCategory(array $key): ?int
    {
        $group = is_string($key['group'] ?? null) ? Craft::$app->getCategories()->getGroupByUid($key['group']) : null;
        if (!$group || !is_string($key['slug'] ?? null) || $key['slug'] === '') {
            return null;
        }

        $candidates = Category::find()
            ->groupId($group->id)
            ->slug(Db::escapeParam($key['slug']))
            ->status(null)
            ->site('*')
            ->unique()
            ->all();

        return self::onlyOnPath($candidates, $key['path'] ?? []);
    }

    /**
     * @param array<mixed> $key
     */
    private static function findTag(array $key): ?int
    {
        $group = is_string($key['group'] ?? null) ? Craft::$app->getTags()->getTagGroupByUid($key['group']) : null;
        if (!$group || !is_string($key['title'] ?? null) || $key['title'] === '') {
            return null;
        }

        // Tag titles are matched exactly, so escape the characters Craft's query param syntax treats specially.
        $ids = Tag::find()
            ->groupId($group->id)
            ->title(Db::escapeParam($key['title']))
            ->status(null)
            ->site('*')
            ->unique()
            ->limit(2)
            ->ids();

        return count($ids) === 1 ? (int)$ids[0] : null;
    }

    /**
     * @param array<mixed> $key
     */
    private static function findUser(array $key): ?int
    {
        foreach (['email', 'username'] as $attribute) {
            $value = $key[$attribute] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }

            $id = User::find()
                ->$attribute(Db::escapeParam($value))
                ->status(null)
                ->ids()[0] ?? null;

            if ($id) {
                return (int)$id;
            }
        }

        return null;
    }

    /**
     * The slugs of an element's ancestors, from the top of the structure down.
     *
     * @return list<string>
     */
    private static function path(ElementInterface $element): array
    {
        $ancestors = $element->getAncestors();
        $ancestors = $ancestors instanceof ElementQueryInterface ? $ancestors->status(null)->all() : $ancestors->all();

        return array_values(array_map(fn(ElementInterface $ancestor) => (string)$ancestor->slug, $ancestors));
    }

    /**
     * Returns the ID of the only candidate whose ancestors' slugs match the path (any candidate when the elements
     * aren't in a structure), or null if none or several do.
     *
     * @param ElementInterface[] $candidates
     * @param mixed $path list of ancestor slugs, or null when the elements aren't in a structure
     */
    private static function onlyOnPath(array $candidates, mixed $path): ?int
    {
        $matches = array_filter($candidates, fn(ElementInterface $candidate) => $path === null || self::path($candidate) === $path);

        return count($matches) === 1 ? reset($matches)->id : null;
    }
}
