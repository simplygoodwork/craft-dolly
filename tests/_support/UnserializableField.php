<?php

namespace simplygoodwork\craftportage\tests\support;

use craft\base\ElementInterface;
use craft\base\Field;
use RuntimeException;

/**
 * A third-party field type whose `serializeValue()` fails, as one that depends on state Portage can't give it would.
 */
final class UnserializableField extends Field
{
    public static function dbType(): string
    {
        return 'text';
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        throw new RuntimeException('Can\'t serialize.');
    }
}
