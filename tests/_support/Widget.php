<?php

namespace simplygoodwork\craftdolly\tests\support;

use craft\base\Element;

/**
 * An element type of a third-party plugin, standing in for one like Formie's forms: it has only Craft's own element
 * tables, a title, a slug and a reference handle.
 */
final class Widget extends Element
{
    public static function displayName(): string
    {
        return 'Widget';
    }

    public static function pluralDisplayName(): string
    {
        return 'Widgets';
    }

    /**
     * Not the short name it's registered with Dolly under (`widget`), as Formie's forms are `form` in reference tags
     * and `formieForm` in the Payload.
     */
    public static function refHandle(): ?string
    {
        return 'gadget';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    protected static function defineSources(string $context): array
    {
        return [['key' => '*', 'label' => 'All widgets']];
    }
}
