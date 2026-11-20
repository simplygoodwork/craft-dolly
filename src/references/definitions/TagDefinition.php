<?php

namespace simplygoodwork\craftdolly\references\definitions;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Tag;
use craft\helpers\Db;
use craft\models\TagGroup;
use simplygoodwork\craftdolly\references\ElementTypeDefinition;

/**
 * Tags. Natural key: group UID + title. Limited to their group, and Stubbed in it.
 */
final class TagDefinition extends ElementTypeDefinition
{
    public function elementClass(): string
    {
        return Tag::class;
    }

    public function naturalKey(ElementInterface $element): ?array
    {
        return $element instanceof Tag ? ['group' => $element->getGroup()->uid, 'title' => $element->title] : null;
    }

    public function find(array $naturalKey): ?int
    {
        $group = self::group($naturalKey);
        $title = $naturalKey['title'] ?? null;
        if (!$group || !is_string($title) || $title === '') {
            return null;
        }

        // Tag titles are matched exactly, so escape the characters Craft's query param syntax treats specially.
        $ids = Tag::find()
            ->groupId($group->id)
            ->title(Db::escapeParam($title))
            ->status(null)
            ->site('*')
            ->unique()
            ->limit(2)
            ->ids();

        return count($ids) === 1 ? (int)$ids[0] : null;
    }

    public function sourceFor(array $naturalKey): ?string
    {
        $group = self::group($naturalKey);

        return $group ? "taggroup:$group->uid" : null;
    }

    public function isInSource(ElementInterface $element, string $source): bool
    {
        return $element instanceof Tag && $source === 'taggroup:' . $element->getGroup()->uid;
    }

    public function sourceName(): string
    {
        return 'tag group';
    }

    public function canBeStubbed(): bool
    {
        return true;
    }

    public function stub(array $naturalKey, ?string $title): ?ElementInterface
    {
        $group = self::group($naturalKey);

        return $group ? new Tag(['groupId' => $group->id, 'title' => $title]) : null;
    }

    public function whyStubForbidden(ElementInterface $stub): string
    {
        return $stub instanceof Tag
            ? "you don't have permission to create tags in the \"{$stub->getGroup()->name}\" group"
            : parent::whyStubForbidden($stub);
    }

    /**
     * @param array<mixed> $naturalKey
     */
    private static function group(array $naturalKey): ?TagGroup
    {
        $uid = $naturalKey['group'] ?? null;

        return is_string($uid) ? Craft::$app->getTags()->getTagGroupByUid($uid) : null;
    }
}
