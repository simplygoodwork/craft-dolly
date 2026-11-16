<?php

namespace simplygoodwork\craftportage\tests\support;

use craft\base\Element;

/**
 * An element type of a third-party plugin, standing in for one like Formie's forms: it has only Craft's own element
 * tables, a title and a slug.
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

    public static function hasTitles(): bool
    {
        return true;
    }

    protected static function defineSources(string $context): array
    {
        return [['key' => '*', 'label' => 'All widgets']];
    }
}
