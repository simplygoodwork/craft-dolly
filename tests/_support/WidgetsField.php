<?php

namespace simplygoodwork\craftdolly\tests\support;

use craft\fields\BaseRelationField;

/**
 * A third-party relation field to widgets, which Dolly has no adapter of its own for.
 */
final class WidgetsField extends BaseRelationField
{
    public static function displayName(): string
    {
        return 'Widgets';
    }

    public static function elementType(): string
    {
        return Widget::class;
    }

    public static function defaultSelectionLabel(): string
    {
        return 'Add a widget';
    }
}
