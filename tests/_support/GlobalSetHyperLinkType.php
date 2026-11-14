<?php

namespace simplygoodwork\craftportage\tests\support;

use craft\elements\GlobalSet;
use verbb\hyper\base\ElementLink;

/**
 * A Hyper link type for an element type Portage doesn't support, standing in for a plugin's (Commerce products, say).
 */
final class GlobalSetHyperLinkType extends ElementLink
{
    public static function displayName(): string
    {
        return 'Global set';
    }

    public static function elementType(): string
    {
        return GlobalSet::class;
    }
}
