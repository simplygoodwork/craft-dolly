<?php

namespace simplygoodwork\craftdolly\tests\support;

use craft\base\Field;

/**
 * A field type Dolly has no adapter for, standing in for a third-party plugin's.
 */
final class UnadaptedField extends Field
{
    public static function displayName(): string
    {
        return 'Unadapted';
    }

    public static function dbType(): string
    {
        return 'text';
    }
}
