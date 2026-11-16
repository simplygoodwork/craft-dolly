<?php

namespace simplygoodwork\craftportage\tests\support;

use craft\base\ElementInterface;
use craft\base\Field;
use RuntimeException;

/**
 * A third-party field type whose `serializeValue()` fails, as one that depends on state Portage can't give it would.
 *
 * It stores nothing, so Craft never serializes it when it saves an element: before Craft 5.7, which added
 * `serializeValueForDb()`, saving serializes every field that has a column with `serializeValue()`.
 */
final class UnserializableField extends Field
{
    public static function dbType(): ?string
    {
        return null;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        throw new RuntimeException('Can\'t serialize.');
    }
}
