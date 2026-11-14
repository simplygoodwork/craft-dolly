<?php

namespace simplygoodwork\craftportage\references;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;

/**
 * The whitelist of element types a Payload may name. The Payload never decides which PHP class is used.
 */
final class ElementTypes
{
    /**
     * @var array<string, class-string<ElementInterface>>
     */
    private const CLASSES = [
        'entry' => Entry::class,
        'category' => Category::class,
        'tag' => Tag::class,
        'user' => User::class,
        'asset' => Asset::class,
    ];

    /**
     * @return class-string<ElementInterface>|null
     */
    public static function classFor(mixed $name): ?string
    {
        return is_string($name) ? (self::CLASSES[$name] ?? null) : null;
    }

    /**
     * Returns whether elements of the class can be Referenced.
     */
    public static function supports(string $class): bool
    {
        return in_array($class, self::CLASSES, true);
    }

    public static function nameOf(ElementInterface $element): ?string
    {
        $name = array_search($element::class, self::CLASSES, true);

        return $name === false ? null : $name;
    }

    /**
     * Returns the element source (`section:<uid>`, `group:<uid>`, `taggroup:<uid>` or `volume:<uid>`) a Reference of
     * the type is limited to, from its Natural key, or null when it isn't limited: users, or a section, group or
     * volume that doesn't exist in this environment.
     *
     * @param array<mixed> $naturalKey
     */
    public static function sourceFor(?string $type, array $naturalKey): ?string
    {
        $container = static fn(string $key) => is_string($naturalKey[$key] ?? null) ? $naturalKey[$key] : null;

        return match ($type) {
            'entry' => ($uid = $container('section')) && Craft::$app->getEntries()->getSectionByUid($uid) ? "section:$uid" : null,
            'category' => ($uid = $container('group')) && Craft::$app->getCategories()->getGroupByUid($uid) ? "group:$uid" : null,
            'tag' => ($uid = $container('group')) && Craft::$app->getTags()->getTagGroupByUid($uid) ? "taggroup:$uid" : null,
            'asset' => ($uid = $container('volume')) && Craft::$app->getVolumes()->getVolumeByUid($uid) ? "volume:$uid" : null,
            default => null,
        };
    }

    /**
     * Returns whether an element belongs to an element source from `sourceFor()`.
     */
    public static function isInSource(ElementInterface $element, string $source): bool
    {
        [$kind, $uid] = explode(':', $source, 2);

        return match (true) {
            $element instanceof Entry => $kind === 'section' && $element->getSection()?->uid === $uid,
            $element instanceof Category => $kind === 'group' && $element->getGroup()->uid === $uid,
            $element instanceof Tag => $kind === 'taggroup' && $element->getGroup()->uid === $uid,
            $element instanceof Asset => $kind === 'volume' && $element->getVolume()->uid === $uid,
            default => false,
        };
    }
}
