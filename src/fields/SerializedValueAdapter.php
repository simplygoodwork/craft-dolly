<?php

namespace simplygoodwork\craftdolly\fields;

use craft\base\FieldInterface;
use craft\fields\Date;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use DateTime;
use simplygoodwork\craftdolly\export\ExportContext;
use simplygoodwork\craftdolly\import\ImportContext;

/**
 * A field whose value is portable as Craft's own `serializeValue()` returns it, and goes back through the field's
 * `normalizeValue()` when it's set on the Target element: number, range, lightswitch, date, time, color, email, table,
 * JSON, country and icon, and the Position and Code fields of the plugins of those names.
 *
 * A date whose field shows the time zone travels as `{date, tz}`, as Craft stores it, so its named time zone survives.
 */
final class SerializedValueAdapter implements FieldAdapter
{
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        if ($field instanceof Date && $field->showTimeZone && $value instanceof DateTime && $value->getTimezone()->getLocation()) {
            return ['date' => DateTimeHelper::toIso8601($value), 'tz' => $value->getTimezone()->getName()];
        }

        $serialized = $field->serializeValue($value, $context->element());

        // Anything that isn't plain data (an object that serializes to itself) is made so by JSON encoding.
        return is_object($serialized) ? Json::decode(Json::encode($serialized)) : $serialized;
    }

    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        return $portable;
    }
}
