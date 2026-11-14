<?php

namespace simplygoodwork\craftportage\tests\support;

use craft\elements\GlobalSet;
use craft\fields\linktypes\BaseElementLinkType;

/**
 * A Link field type for an element type Portage doesn't support, standing in for a plugin's (Commerce products, say).
 */
final class GlobalSetLinkType extends BaseElementLinkType
{
    protected static function elementType(): string
    {
        return GlobalSet::class;
    }
}
