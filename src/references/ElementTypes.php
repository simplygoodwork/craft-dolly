<?php

namespace simplygoodwork\craftportage\references;

use craft\base\ElementInterface;
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
    ];

    /**
     * @return class-string<ElementInterface>|null
     */
    public static function classFor(mixed $name): ?string
    {
        return is_string($name) ? (self::CLASSES[$name] ?? null) : null;
    }

    public static function nameOf(ElementInterface $element): ?string
    {
        $name = array_search($element::class, self::CLASSES, true);

        return $name === false ? null : $name;
    }
}
