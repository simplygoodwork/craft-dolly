<?php

namespace simplygoodwork\craftportage\references\definitions;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\helpers\Db;
use craft\models\CategoryGroup;
use simplygoodwork\craftportage\references\ElementTypeDefinition;
use simplygoodwork\craftportage\references\NaturalKey;

/**
 * Categories. Natural key: group UID + slug, plus the parent slug path. Limited to their group, and Stubbed in it.
 */
final class CategoryDefinition extends ElementTypeDefinition
{
    public function elementClass(): string
    {
        return Category::class;
    }

    public function naturalKey(ElementInterface $element): ?array
    {
        if (!$element instanceof Category) {
            return null;
        }

        return [
            'group' => $element->getGroup()->uid,
            'slug' => $element->slug,
            'path' => NaturalKey::path($element),
        ];
    }

    public function find(array $naturalKey): ?int
    {
        $group = self::group($naturalKey);
        $slug = $naturalKey['slug'] ?? null;
        if (!$group || !is_string($slug) || $slug === '') {
            return null;
        }

        $candidates = Category::find()
            ->groupId($group->id)
            ->slug(Db::escapeParam($slug))
            ->status(null)
            ->site('*')
            ->unique()
            ->all();

        return NaturalKey::onlyOnPath($candidates, $naturalKey['path'] ?? []);
    }

    public function sourceFor(array $naturalKey): ?string
    {
        $group = self::group($naturalKey);

        return $group ? "group:$group->uid" : null;
    }

    public function isInSource(ElementInterface $element, string $source): bool
    {
        return $element instanceof Category && $source === 'group:' . $element->getGroup()->uid;
    }

    public function sourceName(): string
    {
        return 'category group';
    }

    public function canBeStubbed(): bool
    {
        return true;
    }

    public function stub(array $naturalKey, ?string $title): ?ElementInterface
    {
        $group = self::group($naturalKey);
        if (!$group) {
            return null;
        }

        $stub = new Category([
            'groupId' => $group->id,
            'siteId' => array_key_first($group->getSiteSettings()) ?? Craft::$app->getSites()->getPrimarySite()->id,
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
        return $stub instanceof Category
            ? "you don't have permission to create categories in the \"{$stub->getGroup()->name}\" group"
            : parent::whyStubForbidden($stub);
    }

    /**
     * @param array<mixed> $naturalKey
     */
    private static function group(array $naturalKey): ?CategoryGroup
    {
        $uid = $naturalKey['group'] ?? null;

        return is_string($uid) ? Craft::$app->getCategories()->getGroupByUid($uid) : null;
    }
}
